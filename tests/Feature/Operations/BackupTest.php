<?php

use App\Backup\DatabaseBackup;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// La sauvegarde passe par ses propres connexions : elle voit la base telle
// qu'enregistrée (schéma et données de référence), pas les écritures en cours
// dans la transaction du test.
beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/gestion-om-sauvegardes-'.uniqid();
    config(['backup.path' => $this->directory]);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('sauvegarde la base puis la vérifie en la restaurant dans une base temporaire', function () {
    $this->artisan('om:sauvegarde')->expectsOutputToContain('Sauvegarde créée')->assertSuccessful();

    $backup = DatabaseBackup::fromConfig();
    [$file] = $backup->files();
    $manifest = $backup->manifest($file);
    expect($manifest['sha256'])->toBe(hash_file('sha256', $file))
        ->and($manifest['tables'])->toHaveKeys(['mission_orders', 'anomalies', 'audit_logs', 'migrations'])
        ->and($manifest['tables']['zones'])->toBe(2)
        ->and($manifest['tables']['migrations'])->toBe(count(File::files(database_path('migrations'))))
        ->and(fileperms($file) & 0777)->toBe(0600)
        ->and(AuditLog::where('action', 'backup.created')->sole()->new_values['file'])->toBe(basename($file));

    $this->artisan('om:verifier-sauvegarde')->expectsOutputToContain('restaurée et vérifiée')->assertSuccessful();

    expect($backup->manifest($file)['verification'])->toMatchArray(['ok' => true, 'problems' => []])
        ->and(AuditLog::where('action', 'backup.verified')->exists())->toBeTrue()
        // La base temporaire n'existe plus.
        ->and(DB::select('select 1 from pg_database where datname = ?', [config('database.connections.pgsql.database').'_verification']))->toBe([]);
});

it('refuse une sauvegarde modifiée après coup', function () {
    $this->artisan('om:sauvegarde')->assertSuccessful();
    [$file] = DatabaseBackup::fromConfig()->files();
    file_put_contents($file, 'x', FILE_APPEND);

    $this->artisan('om:verifier-sauvegarde', ['fichier' => $file])
        ->expectsOutputToContain('empreinte SHA-256')
        ->assertFailed();

    expect(AuditLog::where('action', 'backup.verification_failed')->exists())->toBeTrue();
});

it('détecte une restauration qui ne retrouve pas les lignes sauvegardées', function () {
    $this->artisan('om:sauvegarde')->assertSuccessful();
    $backup = DatabaseBackup::fromConfig();
    [$file] = $backup->files();
    $manifest = $backup->manifest($file);
    $manifest['tables']['zones'] = 3;
    File::put("{$file}.json", json_encode($manifest));

    $result = $backup->verify($file);

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'])->toBe(['Table zones : 2 lignes restaurées au lieu de 3.']);
});

it('signale une sauvegarde impossible sans laisser de fichier partiel', function () {
    config(['backup.pg_bin' => '/introuvable']);

    $this->artisan('om:sauvegarde')->expectsOutputToContain('Sauvegarde impossible')->assertFailed();

    expect(File::files($this->directory))->toBe([])
        ->and(AuditLog::where('action', 'backup.failed')->exists())->toBeTrue();
    $this->artisan('om:verifier-sauvegarde')->expectsOutputToContain('Aucune sauvegarde à vérifier')->assertFailed();
});

it('supprime les sauvegardes trop anciennes en gardant toujours la plus récente', function () {
    File::ensureDirectoryExists($this->directory);
    foreach (['2026-08-01-020000' => 85, '2026-09-20-020000' => 35, '2026-10-20-020000' => 5] as $stamp => $age) {
        $file = "{$this->directory}/gestion-om-{$stamp}.dump";
        File::put($file, 'sauvegarde');
        File::put("{$file}.json", '{}');
        touch($file, now()->subDays($age)->getTimestamp());
    }

    $deleted = DatabaseBackup::fromConfig()->prune(30);

    expect(array_map(basename(...), $deleted))->toBe(['gestion-om-2026-09-20-020000.dump', 'gestion-om-2026-08-01-020000.dump'])
        ->and(array_map(basename(...), DatabaseBackup::fromConfig()->files()))->toBe(['gestion-om-2026-10-20-020000.dump'])
        ->and(DatabaseBackup::fromConfig()->prune(1))->toBe([]);
});
