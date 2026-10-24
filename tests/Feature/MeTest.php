<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('renvoie le compte connecté', function () {
    $me = User::factory()->create(['name' => 'Moi']);

    $this->actingAs($me)->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Moi');
});

it('change son propre mot de passe', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => 'motdepasse9',
        'password_confirmation' => 'motdepasse9',
    ])->assertNoContent();

    expect(Hash::check('motdepasse9', $me->refresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'user.password_changed')->exists())->toBeTrue();
});

it('exige le mot de passe actuel', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->putJson('/api/me/password', [
        'current_password' => 'mauvais',
        'password' => 'motdepasse9',
        'password_confirmation' => 'motdepasse9',
    ])->assertJsonValidationErrors(['current_password' => 'Le mot de passe actuel est incorrect.']);
});
