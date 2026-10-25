<?php

namespace App\Console\Commands;

use App\Backup\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contrôle d'une installation de production : configuration, base,
 * sauvegardes. Ne modifie rien ; échoue si un point bloquant est trouvé.
 */
class CheckInstallation extends Command
{
    protected $signature = 'om:verifier-installation';

    protected $description = 'Vérifie la configuration de production, la base de données et les sauvegardes';

    private const PASSED = 'OK';

    private const WARNING = 'À surveiller';

    private const TO_FIX = 'À corriger';

    /**
     * @var list<array{string, string, string}>
     */
    private array $results = [];

    /**
     * Version majeure du serveur PostgreSQL, si la base répond.
     */
    private ?int $serverVersion = null;

    public function handle(Migrator $migrator): int
    {
        $this->checkConfiguration();
        $this->checkDatabase($migrator);
        $this->checkBackups();

        $this->table(['Point vérifié', 'État', 'Détail'], $this->results);

        $failures = count(array_filter($this->results, fn (array $result) => $result[1] === self::TO_FIX));
        if ($failures > 0) {
            $this->error("{$failures} point(s) à corriger avant la mise en service.");

            return self::FAILURE;
        }
        $this->info('Installation conforme.');

        return self::SUCCESS;
    }

    private function checkConfiguration(): void
    {
        $this->check('Environnement', app()->environment('production'), 'APP_ENV='.app()->environment(), 'APP_ENV doit valoir production.');
        $this->check('Mode débogage', ! config('app.debug'), 'désactivé', 'APP_DEBUG doit valoir false : les erreurs afficheraient la configuration.');
        $this->check('Clé de chiffrement', filled(config('app.key')), 'définie', 'APP_KEY manquante : lancer php artisan key:generate.');
        $this->check('Adresse en https', str_starts_with((string) config('app.url'), 'https://'), (string) config('app.url'), 'APP_URL doit commencer par https://.');
        $this->check('Liens forcés en https', (bool) config('app.force_https'), 'oui', 'APP_FORCE_HTTPS doit valoir true.');
        $this->check('Cookie de session sécurisé', (bool) config('session.secure'), 'envoyé en https uniquement', 'SESSION_SECURE_COOKIE doit valoir true.');
        $this->check('Fuseau horaire', config('app.timezone') === 'Indian/Antananarivo', (string) config('app.timezone'), 'APP_TIMEZONE doit valoir Indian/Antananarivo.');
        $this->check('Front compilé', is_file(public_path('build/manifest.json')), 'public/build présent', 'Lancer npm ci puis npm run build.');

        $this->warnUnless('Configuration en cache', app()->configurationIsCached(), 'oui', 'Lancer php artisan optimize après chaque déploiement.');
        $level = (string) config('logging.channels.single.level', 'debug');
        $this->warnUnless('Niveau du journal', $level !== 'debug', $level, 'LOG_LEVEL=debug écrit trop de détails en production : warning suffit.');
    }

    private function checkDatabase(Migrator $migrator): void
    {
        try {
            $this->serverVersion = intdiv((int) DB::scalar('SHOW server_version_num'), 10000);
        } catch (Throwable $exception) {
            $this->results[] = ['Base de données', self::TO_FIX, 'connexion impossible : '.$exception->getMessage()];

            return;
        }
        $this->results[] = ['Base de données', self::PASSED, "PostgreSQL {$this->serverVersion}, base ".DB::getDatabaseName()];

        $pending = [];
        if ($migrator->repositoryExists()) {
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
        }
        $this->check('Migrations', $migrator->repositoryExists() && $pending === [], 'à jour', count($pending).' migration(s) en attente : lancer php artisan migrate --force.');

        $accounts = (int) DB::table('users')->where('is_active', true)->count();
        $this->check('Comptes contrôleurs', $accounts > 0, "{$accounts} compte(s) actif(s)", 'Aucun compte : lancer php artisan om:premier-compte.');

        $failedJobs = (int) DB::table('failed_jobs')->count();
        $this->warnUnless('File d\'attente', $failedJobs === 0, 'aucune tâche en échec', "{$failedJobs} tâche(s) en échec : voir php artisan queue:failed.");
    }

    private function checkBackups(): void
    {
        $backup = DatabaseBackup::fromConfig();

        $tool = $backup->toolVersion();
        if ($tool === null) {
            $this->results[] = ['Outils PostgreSQL', self::TO_FIX, 'pg_dump introuvable : installer postgresql-client ou régler BACKUP_PG_BIN.'];
        } elseif ($this->serverVersion !== null && $tool < $this->serverVersion) {
            $this->results[] = ['Outils PostgreSQL', self::TO_FIX, "pg_dump {$tool} est plus ancien que le serveur : installer la même version que le serveur."];
        } else {
            $this->results[] = ['Outils PostgreSQL', self::PASSED, "pg_dump {$tool}"];
        }

        $directory = $backup->directory();
        $writable = is_dir($directory) ? is_writable($directory) : is_writable(dirname($directory));
        $this->check('Dossier des sauvegardes', $writable, $directory, "{$directory} n'est pas accessible en écriture.");

        $latest = $backup->files()[0] ?? null;
        if ($latest === null) {
            $this->results[] = ['Dernière sauvegarde', self::TO_FIX, 'aucune : vérifier la tâche planifiée (schedule:run) ou lancer php artisan om:sauvegarde.'];

            return;
        }

        try {
            $manifest = $backup->manifest($latest);
        } catch (Throwable $exception) {
            $this->results[] = ['Dernière sauvegarde', self::TO_FIX, $exception->getMessage()];

            return;
        }

        $createdAt = Carbon::parse((string) $manifest['created_at']);
        $this->check('Dernière sauvegarde', $createdAt->greaterThan(now()->subHours(26)), basename($latest).', '.$createdAt->diffForHumans(), basename($latest).' date de '.$createdAt->diffForHumans().' : la sauvegarde quotidienne ne tourne plus.');

        /** @var array{ok?: bool, verified_at?: string, problems?: list<string>}|null $verification */
        $verification = $manifest['verification'] ?? null;
        if ($verification === null) {
            $this->results[] = ['Test de restauration', self::WARNING, 'dernière sauvegarde pas encore vérifiée (om:verifier-sauvegarde).'];
        } elseif (($verification['ok'] ?? false) !== true) {
            $this->results[] = ['Test de restauration', self::TO_FIX, implode(' ', $verification['problems'] ?? ['échec'])];
        } else {
            $this->results[] = ['Test de restauration', self::PASSED, 'réussi '.Carbon::parse((string) $verification['verified_at'])->diffForHumans()];
        }
    }

    private function check(string $label, bool $passed, string $ok, string $failure): void
    {
        $this->results[] = [$label, $passed ? self::PASSED : self::TO_FIX, $passed ? $ok : $failure];
    }

    private function warnUnless(string $label, bool $passed, string $ok, string $warning): void
    {
        $this->results[] = [$label, $passed ? self::PASSED : self::WARNING, $passed ? $ok : $warning];
    }
}
