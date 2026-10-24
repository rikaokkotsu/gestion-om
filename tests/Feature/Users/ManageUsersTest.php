<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('liste tous les comptes en indiquant ceux que je gère', function () {
    $me = User::factory()->create();
    $mine = User::factory()->createdBy($me)->create();
    $other = User::factory()->create();

    $response = $this->actingAs($me)->getJson('/api/users')->assertOk();

    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId[$mine->id]['can_manage'])->toBeTrue()
        ->and($byId[$other->id]['can_manage'])->toBeFalse()
        ->and($byId[$me->id]['can_manage'])->toBeFalse();
});

it('crée un compte rattaché à son créateur et le journalise', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->postJson('/api/users', [
        'name' => 'Nouveau Contrôleur',
        'email' => 'nouveau@example.com',
        'password' => 'motdepasse1',
        'password_confirmation' => 'motdepasse1',
    ])->assertCreated()->assertJsonPath('data.can_manage', true);

    $created = User::where('email', 'nouveau@example.com')->firstOrFail();
    expect($created->created_by_id)->toBe($me->id)
        ->and(Hash::check('motdepasse1', $created->password))->toBeTrue();

    $log = AuditLog::where('action', 'user.created')->firstOrFail();
    expect($log->user_id)->toBe($me->id)
        ->and($log->auditable_id)->toBe($created->id)
        ->and($log->new_values)->not->toHaveKey('password');
});

it('valide le mot de passe d\'un nouveau compte', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->postJson('/api/users', [
        'name' => 'X',
        'email' => 'x@example.com',
        'password' => 'court',
        'password_confirmation' => 'court',
    ])->assertJsonValidationErrors('password');
});

it('modifie un compte que j\'ai créé, y compris son mot de passe', function () {
    $me = User::factory()->create();
    $mine = User::factory()->createdBy($me)->create(['name' => 'Ancien nom']);

    $this->actingAs($me)->patchJson("/api/users/{$mine->id}", [
        'name' => 'Nouveau nom',
        'password' => 'motdepasse2',
        'password_confirmation' => 'motdepasse2',
    ])->assertOk()->assertJsonPath('data.name', 'Nouveau nom');

    expect(Hash::check('motdepasse2', $mine->refresh()->password))->toBeTrue();

    $log = AuditLog::where('action', 'user.updated')->firstOrFail();
    expect($log->old_values)->toBe(['name' => 'Ancien nom'])
        ->and($log->new_values)->toBe(['name' => 'Nouveau nom', 'password_reset' => true]);
});

it('refuse de modifier un compte créé par quelqu\'un d\'autre', function () {
    $me = User::factory()->create();
    $other = User::factory()->createdBy(User::factory()->create())->create();

    $this->actingAs($me)->patchJson("/api/users/{$other->id}", ['name' => 'Piraté'])
        ->assertForbidden();

    expect($other->refresh()->name)->not->toBe('Piraté');
});

it('refuse de modifier son propre compte par cette route', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->patchJson("/api/users/{$me->id}", ['name' => 'Moi'])
        ->assertForbidden();
});
