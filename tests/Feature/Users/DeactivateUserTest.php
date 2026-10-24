<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('désactive un compte que j\'ai créé au lieu de le supprimer', function () {
    $me = User::factory()->create();
    $mine = User::factory()->createdBy($me)->create();

    $this->actingAs($me)->postJson("/api/users/{$mine->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $mine->refresh();
    expect($mine->is_active)->toBeFalse()
        ->and($mine->deactivated_at)->not->toBeNull()
        ->and(User::count())->toBe(2);
});

it('confie les comptes créés par le compte désactivé à son créateur', function () {
    $me = User::factory()->create();
    $mine = User::factory()->createdBy($me)->create();
    $grandChildren = User::factory()->count(2)->createdBy($mine)->create();

    $this->actingAs($me)->postJson("/api/users/{$mine->id}/deactivate")->assertOk();

    foreach ($grandChildren as $child) {
        expect($child->refresh()->created_by_id)->toBe($me->id);
    }

    $log = AuditLog::where('action', 'user.deactivated')->firstOrFail();
    expect($log->new_values['reassigned_user_ids'])->toEqualCanonicalizing($grandChildren->pluck('id')->all());
});

it('ferme les sessions du compte désactivé', function () {
    $me = User::factory()->create();
    $mine = User::factory()->createdBy($me)->create();
    DB::table('sessions')->insert([
        'id' => 'session-a-fermer',
        'user_id' => $mine->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($me)->postJson("/api/users/{$mine->id}/deactivate")->assertOk();

    expect(DB::table('sessions')->where('user_id', $mine->id)->exists())->toBeFalse();
});

it('refuse de désactiver un compte créé par quelqu\'un d\'autre', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($me)->postJson("/api/users/{$other->id}/deactivate")->assertForbidden();

    expect($other->refresh()->is_active)->toBeTrue();
});

it('réactive un compte que j\'ai désactivé', function () {
    $me = User::factory()->create();
    $mine = User::factory()->createdBy($me)->inactive()->create();

    $this->actingAs($me)->postJson("/api/users/{$mine->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($mine->refresh()->deactivated_at)->toBeNull()
        ->and(AuditLog::where('action', 'user.reactivated')->exists())->toBeTrue();
});
