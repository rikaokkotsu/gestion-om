<?php

use App\Models\User;
use App\Support\Audit;

it('liste le journal du plus récent au plus ancien, filtrable par action', function () {
    $me = User::factory()->create();
    Audit::record('auth.login', $me, actor: $me);
    Audit::record('user.created', $me, actor: $me);

    $this->actingAs($me)->getJson('/api/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'user.created')
        ->assertJsonPath('data.0.user.id', $me->id)
        ->assertJsonPath('data.0.auditable_type', 'user');

    $this->actingAs($me)->getJson('/api/audit-logs?action=auth.')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'auth.login');
});

it('ne recopie jamais un mot de passe dans le journal', function () {
    $me = User::factory()->create();

    $log = Audit::record('user.updated', $me, ['password' => 'a'], ['password' => 'b', 'name' => 'X'], $me);

    expect($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe(['name' => 'X']);
});
