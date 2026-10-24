<?php

use App\Models\AuditLog;
use App\Models\User;

it('connecte un contrôleur actif et journalise la connexion', function () {
    $user = User::factory()->create(['email' => 'a@example.com']);

    $this->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'a@example.com');

    $this->assertAuthenticatedAs($user);
    expect(AuditLog::where('action', 'auth.login')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('refuse un mauvais mot de passe et journalise la tentative', function () {
    User::factory()->create(['email' => 'a@example.com']);

    $this->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'faux'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Ces identifiants ne correspondent à aucun compte actif.']);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.login_failed')->first()?->new_values)->toBe(['email' => 'a@example.com']);
});

it('refuse un compte désactivé avec le même message', function () {
    User::factory()->inactive()->create(['email' => 'a@example.com']);

    $this->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
        ->assertJsonValidationErrors(['email' => 'Ces identifiants ne correspondent à aucun compte actif.']);

    $this->assertGuest();
});

it('limite les tentatives de connexion', function () {
    User::factory()->create(['email' => 'a@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'faux']);
    }

    $this->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
        ->assertTooManyRequests();
});

it('déconnecte le contrôleur', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/auth/logout')->assertNoContent();

    $this->assertGuest('web');
    expect(AuditLog::where('action', 'auth.logout')->exists())->toBeTrue();
});

it('refuse l\'API sans session', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

it('coupe l\'accès d\'un compte désactivé encore connecté', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)->getJson('/api/me')->assertForbidden();
});
