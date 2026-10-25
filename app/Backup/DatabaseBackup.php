<?php

namespace App\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Sauvegarde de la base PostgreSQL avec pg_dump, et vérification de chaque
 * sauvegarde par une restauration complète dans une base temporaire.
 *
 * À côté de chaque fichier .dump, un manifeste .json garde son empreinte
 * SHA-256 et le nombre de lignes de chaque table au moment exact de la
 * sauvegarde (même instantané que pg_dump) : une restauration est réussie
 * quand elle retrouve ces nombres à l'identique.
 */
final class DatabaseBackup
{
    private const SNAPSHOT_CONNECTION = 'backup_snapshot';

    private const ADMIN_CONNECTION = 'backup_admin';

    private const CHECK_CONNECTION = 'backup_check';

    /**
     * @param  array<string, mixed>  $database  configuration de la connexion sauvegardée
     */
    public function __construct(
        private readonly array $database,
        private readonly string $directory,
        private readonly string $binDirectory = '',
        private readonly ?string $verifyDatabase = null,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $database */
        $database = config('database.connections.'.config('database.default'));

        return new self(
            $database,
            (string) config('backup.path'),
            (string) config('backup.pg_bin'),
            config('backup.verify_database') ?: null,
        );
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Sauvegarde cohérente : les nombres de lignes et le contenu du fichier
     * viennent du même instantané de la base.
     *
     * @return array<string, mixed> le manifeste, avec le chemin du fichier sous « path »
     */
    public function create(): array
    {
        File::ensureDirectoryExists($this->directory, 0700);
        $name = 'gestion-om-'.now()->format('Y-m-d-His');
        $path = "{$this->directory}/{$name}.dump";
        $partial = "{$path}.partial";

        $connection = $this->connection(self::SNAPSHOT_CONNECTION, (string) $this->database['database']);
        try {
            $connection->beginTransaction();
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $snapshot = (string) $connection->scalar('SELECT pg_export_snapshot()');
            $tables = $this->countRows($connection);

            $this->run('pg_dump', [
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                "--snapshot={$snapshot}",
                "--file={$partial}",
                '--dbname='.$this->database['database'],
            ]);
            $connection->commit();
        } catch (\Throwable $exception) {
            File::delete($partial);
            throw $exception;
        } finally {
            DB::purge(self::SNAPSHOT_CONNECTION);
        }

        File::move($partial, $path);
        chmod($path, 0600);

        $manifest = [
            'file' => basename($path),
            'created_at' => now()->toIso8601String(),
            'database' => $this->database['database'],
            'size' => File::size($path),
            'sha256' => hash_file('sha256', $path),
            'tables' => $tables,
        ];
        $this->writeManifest($path, $manifest);

        return [...$manifest, 'path' => $path];
    }

    /**
     * Restaure la sauvegarde dans une base temporaire, compare chaque table au
     * manifeste, puis supprime la base. Le résultat est ajouté au manifeste.
     *
     * @return array{ok: bool, problems: list<string>, tables: int, rows: int}
     */
    public function verify(string $path): array
    {
        $manifest = $this->manifest($path);
        $problems = [];

        if (! is_file($path)) {
            throw new RuntimeException("Fichier de sauvegarde introuvable : {$path}");
        }
        if (hash_file('sha256', $path) !== ($manifest['sha256'] ?? null)) {
            $problems[] = 'L\'empreinte SHA-256 du fichier ne correspond pas au manifeste : le fichier a été modifié ou est incomplet.';
        }

        $target = $this->verifyDatabase ?? $this->database['database'].'_verification';
        $restored = [];

        if ($problems === []) {
            $admin = $this->connection(self::ADMIN_CONNECTION, (string) $this->database['database']);
            try {
                $admin->statement('DROP DATABASE IF EXISTS '.$this->quote($target).' WITH (FORCE)');
                $admin->statement('CREATE DATABASE '.$this->quote($target));
                $this->run('pg_restore', ['--no-owner', '--no-privileges', '--exit-on-error', "--dbname={$target}", $path]);
                $restored = $this->countRows($this->connection(self::CHECK_CONNECTION, $target));
            } finally {
                DB::purge(self::CHECK_CONNECTION);
                $admin->statement('DROP DATABASE IF EXISTS '.$this->quote($target).' WITH (FORCE)');
                DB::purge(self::ADMIN_CONNECTION);
            }
        }

        /** @var array<string, int> $expected */
        $expected = $manifest['tables'] ?? [];
        if ($problems === []) {
            foreach ($expected as $table => $count) {
                $found = $restored[$table] ?? null;
                if ($found === null) {
                    $problems[] = "Table {$table} absente de la base restaurée.";
                } elseif ($found !== $count) {
                    $problems[] = "Table {$table} : {$found} lignes restaurées au lieu de {$count}.";
                }
            }
            if ($expected === []) {
                $problems[] = 'Le manifeste ne liste aucune table.';
            }
        }

        $result = [
            'ok' => $problems === [],
            'problems' => $problems,
            'tables' => count($restored),
            'rows' => array_sum($restored),
        ];
        $this->writeManifest($path, [...$manifest, 'verification' => [
            'verified_at' => now()->toIso8601String(),
            'ok' => $result['ok'],
            'problems' => $problems,
        ]]);

        return $result;
    }

    /**
     * Sauvegardes présentes, de la plus récente à la plus ancienne.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = glob("{$this->directory}/gestion-om-*.dump") ?: [];
        rsort($files);

        return $files;
    }

    /**
     * Supprime les sauvegardes plus anciennes que la durée de conservation,
     * en gardant toujours la plus récente.
     *
     * @return list<string> fichiers supprimés
     */
    public function prune(int $keepDays): array
    {
        $limit = now()->subDays($keepDays)->getTimestamp();
        $deleted = [];

        foreach (array_slice($this->files(), 1) as $file) {
            if (filemtime($file) < $limit) {
                File::delete([$file, "{$file}.json"]);
                $deleted[] = $file;
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(string $path): array
    {
        $file = "{$path}.json";
        if (! is_file($file)) {
            throw new RuntimeException("Manifeste introuvable : {$file}");
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * Version majeure de pg_dump, ou null s'il est introuvable.
     */
    public function toolVersion(): ?int
    {
        $result = Process::run([$this->binary('pg_dump'), '--version']);

        return $result->successful() && preg_match('/(\d+)\.\d+/', $result->output(), $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * Nombre de lignes de chaque table du schéma de l'application.
     *
     * @return array<string, int>
     */
    private function countRows(Connection $connection): array
    {
        $tables = $connection->table('pg_tables')->where('schemaname', 'public')->orderBy('tablename')->pluck('tablename');
        $counts = [];
        foreach ($tables as $table) {
            $counts[(string) $table] = (int) $connection->scalar('SELECT count(*) FROM '.$this->quote((string) $table));
        }

        return $counts;
    }

    /**
     * Connexion distincte de celle de l'application, vers la base indiquée.
     */
    private function connection(string $name, string $database): Connection
    {
        config(["database.connections.{$name}" => [...$this->database, 'database' => $database]]);
        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * Lance un outil PostgreSQL ; le mot de passe passe par l'environnement, jamais par la ligne de commande.
     *
     * @param  list<string>  $arguments
     */
    private function run(string $tool, array $arguments): void
    {
        $result = Process::timeout(3600)
            ->env(array_filter([
                'PGHOST' => (string) $this->database['host'],
                'PGPORT' => (string) $this->database['port'],
                'PGUSER' => (string) $this->database['username'],
                'PGPASSWORD' => (string) $this->database['password'],
                'PGSSLMODE' => (string) ($this->database['sslmode'] ?? ''),
            ], fn (string $value) => $value !== ''))
            ->run([$this->binary($tool), ...$arguments]);

        if ($result->failed()) {
            throw new RuntimeException("{$tool} a échoué : ".trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function binary(string $tool): string
    {
        return $this->binDirectory === '' ? $tool : rtrim($this->binDirectory, '/')."/{$tool}";
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        File::put("{$path}.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod("{$path}.json", 0600);
    }
}
