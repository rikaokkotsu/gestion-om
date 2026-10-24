<?php

use App\Models\User;

it('crée le premier compte sans créateur', function () {
    $this->artisan('om:premier-compte', ['--name' => 'Admin', '--email' => 'Admin@Example.com'])
        ->expectsQuestion('Mot de passe (10 caractères minimum, lettres et chiffres)', 'motdepasse1')
        ->assertSuccessful();

    $user = User::sole();
    expect($user->email)->toBe('admin@example.com')
        ->and($user->created_by_id)->toBeNull();
});

it('refuse de créer un premier compte si des comptes existent', function () {
    User::factory()->create();

    $this->artisan('om:premier-compte', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('refuse un mot de passe trop faible', function () {
    $this->artisan('om:premier-compte', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Mot de passe (10 caractères minimum, lettres et chiffres)', 'court')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
