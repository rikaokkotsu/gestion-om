<?php

use App\Actions\SaveMissionOrder;
use App\Jobs\RunControlBatch;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\ControlRun;
use App\Models\MissionOrder;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
});

it('contrôle un OM à la demande et liste ses anomalies, à traiter d\'abord', function () {
    $order = $this->saveOrder(['declared_duration' => 4, 'assignment_allowance' => 300000]);

    $this->postJson("/api/mission-orders/{$order->id}/control")
        ->assertOk()
        ->assertJsonPath('data.status', 'DONE')
        ->assertJsonPath('data.trigger_label', 'Sur un OM')
        ->assertJsonPath('data.anomalies_created', 2);

    $this->getJson("/api/mission-orders/{$order->id}")
        ->assertJsonPath('data.control_status_label', 'Contrôle en cours')
        ->assertJsonPath('data.open_anomalies_count', 2)
        ->assertJsonPath('data.changed_since_control', false);

    $this->getJson("/api/mission-orders/{$order->id}/anomalies")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.rule_code', 'AFF-01')
        ->assertJsonPath('data.0.priority_label', 'Haute')
        ->assertJsonPath('data.0.state_label', 'Proposée')
        ->assertJsonPath('data.0.rule_label', 'Indemnité d\'affectation sur un OM de mission')
        ->assertJsonPath('data.0.transitions.0.value', 'CONFIRMED')
        ->assertJsonPath('data.0.editable_fields', ['priority'])
        ->assertJsonPath('data.1.rule_code', 'DAT-02');
});

it('enregistre un signalement manuel, confirmé d\'emblée, sans modifier l\'OM', function () {
    $order = $this->saveOrder();
    $member = $order->employees()->firstOrFail();
    $snapshot = json_encode(SaveMissionOrder::snapshot($order));

    $response = $this->postJson('/api/anomalies', [
        'mission_order_id' => $order->id,
        'member_id' => $member->id,
        'category' => 'G',
        'description' => 'Le justificatif d\'hébergement porte une nuitée de moins.',
        'comment' => 'Vu sur la pièce jointe.',
    ])->assertCreated()
        ->assertJsonPath('data.source_label', 'Signalement manuel')
        ->assertJsonPath('data.state', 'CONFIRMED')
        ->assertJsonPath('data.priority', 'MEDIUM')
        ->assertJsonPath('data.employee.matricule', 'M001')
        ->assertJsonPath('data.reported_by.id', $this->user->id)
        ->assertJsonPath('data.events.0.type', 'CREATED')
        ->assertJsonPath('data.events.0.comment', 'Vu sur la pièce jointe.')
        ->assertJsonPath('data.events.0.user.id', $this->user->id);

    $order = MissionOrder::findOrFail($order->id);
    expect($order->control_status->value)->toBe('WITH_ANOMALIES')
        ->and(json_encode(SaveMissionOrder::snapshot($order)))->toBe($snapshot)
        ->and(AuditLog::where('action', 'anomaly.created')->firstOrFail()->auditable_id)->toBe($response->json('data.id'));
});

it('refuse un signalement incomplet ou un missionnaire d\'un autre OM', function () {
    $order = $this->saveOrder();
    $other = $this->saveOrder(['reference' => 'OM-2026-002']);

    $this->postJson('/api/anomalies', [
        'mission_order_id' => $order->id,
        'member_id' => $other->employees()->value('id'),
        'category' => 'Z',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['member_id', 'category', 'description'])
        ->assertJsonPath('errors.description.0', 'Le champ description est obligatoire.');
});

it('fait évoluer l\'état d\'une anomalie et garde l\'historique', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);
    $anomaly = Anomaly::firstOrFail();

    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'DISMISSED'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.comment.0', 'Indiquez le motif pour lequel l\'anomalie est écartée.');
    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'TRANSMITTED'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.state.0', 'Une anomalie à l\'état « Proposée » ne peut pas passer à l\'état « Transmise ».');

    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'CONFIRMED'])->assertOk()->assertJsonPath('data.state', 'CONFIRMED');
    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'TRANSMITTED', 'comment' => 'Envoyée au service RH.'])->assertOk();
    $this->postJson("/api/anomalies/{$anomaly->id}/comments", ['comment' => 'Réponse attendue sous huit jours.'])->assertOk();
    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'CLOSED'])
        ->assertOk()
        ->assertJsonPath('data.state_label', 'Clôturée')
        ->assertJsonPath('data.transitions.0.value', 'CONFIRMED')
        ->assertJsonCount(5, 'data.events')
        ->assertJsonPath('data.events.2.to_state_label', 'Transmise')
        ->assertJsonPath('data.events.2.comment', 'Envoyée au service RH.')
        ->assertJsonPath('data.events.3.type_label', 'Commentaire');

    expect($anomaly->refresh()->confirmed_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'anomaly.state_changed')->count())->toBe(3)
        ->and($order->refresh()->control_status->value)->toBe('WITH_ANOMALIES');
});

it('ne modifie que la priorité d\'une anomalie automatique, tout pour un signalement manuel', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);
    $automatic = Anomaly::firstOrFail();

    $this->patchJson("/api/anomalies/{$automatic->id}", ['description' => 'Autre texte'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('description');
    $this->patchJson("/api/anomalies/{$automatic->id}", ['priority' => 'LOW'])
        ->assertOk()
        ->assertJsonPath('data.priority_label', 'Basse')
        ->assertJsonPath('data.events.1.type', 'UPDATED')
        ->assertJsonPath('data.events.1.changes.priority.old', 'MEDIUM')
        ->assertJsonPath('data.events.1.changes.priority.new', 'LOW');

    $manual = $this->postJson('/api/anomalies', ['mission_order_id' => $order->id, 'category' => 'C', 'description' => 'Tampon illisible.'])->json('data.id');
    $this->patchJson("/api/anomalies/{$manual}", ['category' => 'B', 'description' => 'Signature du validateur illisible.', 'member_id' => $order->employees()->value('id')])
        ->assertOk()
        ->assertJsonPath('data.category_label', 'Validations')
        ->assertJsonPath('data.employee.matricule', 'M001');

    expect(AuditLog::where('action', 'anomaly.updated')->count())->toBe(2);
});

it('filtre la liste des anomalies et écarte celles des OM supprimés', function () {
    $first = $this->saveOrder(['declared_duration' => 4]);
    $second = $this->saveOrder(['reference' => 'OM-2026-777', 'employees' => [$this->member($this->rakoto, ['declared_per_diem' => 500000])]]);
    $deleted = $this->saveOrder(['reference' => 'OM-2026-999', 'assignment_allowance' => 1000]);
    foreach ([$first, $second, $deleted] as $order) {
        $this->control($order);
    }
    $deleted->delete();

    $this->getJson('/api/anomalies?state=open')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/anomalies?category=G')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.rule_code', 'PD-01');
    $this->getJson('/api/anomalies?rule_code=DAT-02')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.mission_order.reference', 'OM-2026-001');
    $this->getJson("/api/anomalies?employee_id={$this->rakoto->id}")->assertJsonPath('meta.total', 1);
    $this->getJson("/api/anomalies?mission_order_id={$first->id}")->assertJsonPath('meta.total', 1);
    $this->getJson('/api/anomalies?search=777')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/anomalies?search=rakoto')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/anomalies?state=CONFIRMED')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/anomalies?from='.now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
    $this->getJson('/api/anomalies?from='.now()->toDateString().'&to='.now()->toDateString())->assertJsonPath('meta.total', 2);
    $this->getJson('/api/anomalies?state=nope')->assertUnprocessable();
});

it('liste les règles et permet d\'en désactiver une, avec trace', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);

    $this->getJson('/api/control-rules')
        ->assertOk()
        ->assertJsonCount(30, 'data')
        ->assertJsonPath('data.0.code', 'EMP-01')
        ->assertJsonPath('data.12.code', 'DAT-02')
        ->assertJsonPath('data.12.open_anomalies_count', 1)
        ->assertJsonPath('data.12.is_enabled', true);

    $this->patchJson('/api/control-rules/DAT-02', ['is_enabled' => false])->assertOk()->assertJsonPath('data.is_enabled', false);
    $this->patchJson('/api/control-rules/XXX-99', ['is_enabled' => false])->assertNotFound();

    $this->getJson('/api/control-rules')->assertJsonPath('data.12.is_enabled', false);
    $log = AuditLog::where('action', 'control_rule.updated')->firstOrFail();
    expect($log->new_values)->toBe(['is_enabled' => false])
        ->and($log->auditable_type)->toBe('control_rule');
    $this->getJson('/api/audit-logs')->assertJsonPath('data.0.subject_label', 'DAT-02 : Durée incohérente avec les dates');
});

it('contrôle un lot d\'OM dans la file d\'attente, selon la période et l\'avancement', function () {
    $november = $this->saveOrder(['declared_duration' => 4]);
    $this->saveOrder(['reference' => 'OM-2026-002', 'departure_at' => '2026-12-03 08:00', 'return_at' => '2026-12-07 17:30', 'declared_duration' => 4]);
    $clean = $this->saveOrder(['reference' => 'OM-2026-003', 'departure_at' => '2026-11-10 08:00', 'return_at' => '2026-11-14 17:30']);

    $response = $this->postJson('/api/control-runs', ['scope' => 'all', 'departure_from' => '2026-11-01', 'departure_to' => '2026-11-30'])
        ->assertAccepted()
        ->assertJsonPath('data.trigger', 'BATCH')
        ->assertJsonPath('data.status', 'DONE')
        ->assertJsonPath('data.orders_count', 2)
        ->assertJsonPath('data.processed_count', 2)
        ->assertJsonPath('data.anomalies_created', 1)
        ->assertJsonPath('data.filters.departure_from', '2026-11-01')
        ->assertJsonPath('data.filters.scope', 'all');

    expect(Anomaly::where('mission_order_id', $november->id)->count())->toBe(1)
        ->and($clean->refresh()->control_status->value)->toBe('NO_ANOMALY')
        ->and(AuditLog::where('action', 'control.batch')->count())->toBe(1);

    // « À contrôler » : jamais contrôlés, ou modifiés depuis le dernier contrôle.
    $this->travel(1)->minutes();
    $this->saveOrder(['declared_duration' => 5], $november);
    $this->postJson('/api/control-runs', ['scope' => 'pending'])
        ->assertAccepted()
        ->assertJsonPath('data.orders_count', 2)
        ->assertJsonPath('data.anomalies_closed', 1);

    $this->getJson("/api/control-runs/{$response->json('data.id')}")->assertOk()->assertJsonPath('data.launched_by.id', $this->user->id);
    $this->getJson('/api/control-runs?trigger=BATCH')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/control-runs', ['scope' => 'all', 'departure_from' => '2026-11-30', 'departure_to' => '2026-11-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('departure_to');
    expect(ControlRun::count())->toBe(2);
});

it('marque un contrôle par lot en échec si le traitement s\'interrompt', function () {
    $run = ControlRun::create(['trigger' => 'BATCH', 'filters' => ['scope' => 'all']]);

    (new RunControlBatch($run->id))->failed(new RuntimeException('Connexion perdue.'));

    expect($run->refresh()->status->value)->toBe('FAILED')
        ->and($run->error)->toBe('Connexion perdue.');
});
