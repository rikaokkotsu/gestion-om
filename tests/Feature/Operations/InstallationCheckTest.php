<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/gestion-om-installation-'.uniqid();
    config(['backup.path' => "{$this->directory}/sauvegardes"]);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

/**
 * Lance la vérification ; rend son code de sortie et ce qu'elle affiche.
 *
 * @return array{int, string}
 */
function checkInstallation(): array
{
    $status = Artisan::call('om:verifier-installation');

    return [$status, Artisan::output()];
}

it('liste les points à corriger d\'une installation qui n\'est pas prête pour la production', function () {
    config(['app.debug' => true, 'app.url' => 'http://localhost', 'app.force_https' => false, 'session.secure' => false]);

    [$status, $output] = checkInstallation();

    expect($status)->toBe(1)
        ->and($output)->toContain('APP_ENV doit valoir production.')
        ->toContain('APP_DEBUG doit valoir false')
        ->toContain('APP_URL doit commencer par https://.')
        ->toContain('SESSION_SECURE_COOKIE doit valoir true.')
        ->toContain('Aucun compte : lancer php artisan om:premier-compte.')
        ->toContain('aucune : vérifier la tâche planifiée')
        ->toContain('point(s) à corriger avant la mise en service.');
});

it('valide une installation de production dont la dernière sauvegarde a été restaurée avec succès', function () {
    app()->detectEnvironment(fn () => 'production');
    app()->usePublicPath($this->directory);
    File::ensureDirectoryExists("{$this->directory}/build");
    File::put("{$this->directory}/build/manifest.json", '{}');
    config([
        'app.debug' => false,
        'app.url' => 'https://om.example.mg',
        'app.force_https' => true,
        'session.secure' => true,
        'logging.channels.single.level' => 'warning',
    ]);
    User::factory()->create();

    $this->artisan('om:sauvegarde')->assertSuccessful();
    [$status, $output] = checkInstallation();
    expect($output)->toContain('dernière sauvegarde pas encore vérifiée');

    $this->artisan('om:verifier-sauvegarde')->assertSuccessful();
    [$status, $output] = checkInstallation();

    expect($status)->toBe(0)
        ->and($output)->toContain('Installation conforme.')
        ->toMatch('/Dernière sauvegarde\s*\|\s*OK\s*\|\s*gestion-om-/')
        ->toMatch('/Test de restauration\s*\|\s*OK\s*\|\s*réussi/')
        ->toMatch('/Migrations\s*\|\s*OK/')
        ->not->toContain('À corriger');
});

it('signale une sauvegarde de plus d\'un jour : la tâche planifiée ne tourne plus', function () {
    $this->travel(-2)->days();
    $this->artisan('om:sauvegarde')->assertSuccessful();
    $this->travelBack();

    [, $output] = checkInstallation();

    expect($output)->toContain('la sauvegarde quotidienne ne tourne plus.');
});
