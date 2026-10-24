<?php

use App\Enums\AnomalyEventType;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Enums\ControlRunStatus;
use App\Enums\ControlStatus;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\ControlRuleSetting;
use App\Models\MissionOrder;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
});

it('contrôle un OM sans anomalie sans le modifier', function () {
    $order = $this->saveOrder();
    $updatedAt = $order->fresh()?->updated_at?->toIso8601String();
    $this->travel(1)->minutes();

    $run = $this->control($order);

    $order->refresh();
    expect($run->status)->toBe(ControlRunStatus::Done)
        ->and($run->anomalies_created)->toBe(0)
        ->and(Anomaly::count())->toBe(0)
        ->and($order->control_status)->toBe(ControlStatus::NoAnomaly)
        ->and($order->last_controlled_at)->not->toBeNull()
        ->and($order->updated_at?->toIso8601String())->toBe($updatedAt)
        ->and($order->changedSinceControl())->toBeFalse()
        ->and(AuditLog::where('action', 'control.run')->firstOrFail()->new_values)
        ->toMatchArray(['control_run_id' => $run->id, 'anomalies_created' => 0]);
});

it('propose les anomalies détectées sans les dupliquer d\'un contrôle à l\'autre', function () {
    $order = $this->saveOrder(['declared_duration' => 4, 'assignment_allowance' => 300000]);

    $first = $this->control($order);
    $second = $this->control($order);

    $anomalies = Anomaly::orderBy('rule_code')->get();
    expect($first->anomalies_created)->toBe(2)
        ->and($second->anomalies_created)->toBe(0)
        ->and($anomalies->pluck('rule_code')->all())->toBe(['AFF-01', 'DAT-02'])
        ->and($anomalies->every(fn (Anomaly $anomaly) => $anomaly->state === AnomalyState::Proposed && $anomaly->source === AnomalySource::Automatic))->toBeTrue()
        ->and($anomalies->firstOrFail()->events()->pluck('type')->all())->toBe([AnomalyEventType::Created])
        ->and($order->refresh()->control_status)->toBe(ControlStatus::InProgress);
});

it('rattache l\'anomalie au missionnaire concerné', function () {
    $order = $this->saveOrder(['employees' => [$this->member($this->rakoto, ['declared_per_diem' => 500000])]]);

    $this->control($order);

    $anomaly = Anomaly::where('rule_code', 'PD-01')->firstOrFail();
    expect($anomaly->employee_id)->toBe($this->rakoto->id)
        ->and($anomaly->mission_order_employee_id)->toBe($order->employees()->value('id'))
        ->and($anomaly->category->value)->toBe('G')
        ->and($anomaly->priority->value)->toBe('HIGH');
});

it('clôt une anomalie proposée que le contrôle ne retrouve plus', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);

    $this->saveOrder(['declared_duration' => 5], $order);
    $run = $this->control($order);

    $anomaly = Anomaly::firstOrFail();
    expect($run->anomalies_closed)->toBe(1)
        ->and($anomaly->state)->toBe(AnomalyState::Closed)
        ->and($anomaly->no_longer_detected_at)->not->toBeNull()
        ->and($anomaly->events()->get()->last()->comment)->toBe('Clôturée par le contrôle : la situation n\'est plus détectée.')
        ->and($order->refresh()->control_status)->toBe(ControlStatus::NoAnomaly);

    // La situation revient : une nouvelle anomalie est proposée.
    $this->saveOrder(['declared_duration' => 4], $order);
    expect($this->control($order)->anomalies_created)->toBe(1)
        ->and(Anomaly::where('state', 'PROPOSED')->count())->toBe(1);
});

it('laisse au contrôleur une anomalie confirmée qui n\'est plus détectée', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);
    $anomaly = Anomaly::firstOrFail();
    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'CONFIRMED'])->assertOk();

    $this->saveOrder(['declared_duration' => 5], $order);
    $this->control($order);

    $anomaly->refresh();
    expect($anomaly->state)->toBe(AnomalyState::Confirmed)
        ->and($anomaly->no_longer_detected_at)->not->toBeNull()
        ->and($anomaly->events()->get()->last()?->type)->toBe(AnomalyEventType::NoLongerDetected)
        ->and($order->refresh()->control_status)->toBe(ControlStatus::WithAnomalies);

    // De nouveau détectée : la même anomalie, avec sa nouvelle description.
    $this->saveOrder(['declared_duration' => 3], $order);
    $this->control($order);

    $anomaly->refresh();
    expect(Anomaly::count())->toBe(1)
        ->and($anomaly->no_longer_detected_at)->toBeNull()
        ->and($anomaly->description)->toContain('Durée déclarée : 3 jours')
        ->and($anomaly->events()->get()->last()->changes['description']['old'] ?? null)->toContain('Durée déclarée : 4 jours');
});

it('ne repropose pas une anomalie écartée tant que la situation ne change pas', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);
    $anomaly = Anomaly::firstOrFail();
    $this->postJson("/api/anomalies/{$anomaly->id}/transition", ['state' => 'DISMISSED', 'comment' => 'Convention particulière.'])->assertOk();

    expect($this->control($order)->anomalies_created)->toBe(0)
        ->and($order->refresh()->control_status)->toBe(ControlStatus::NoAnomaly);

    $this->saveOrder(['declared_duration' => 3], $order);
    expect($this->control($order)->anomalies_created)->toBe(1)
        ->and(Anomaly::latest('id')->firstOrFail()->state)->toBe(AnomalyState::Proposed);
});

it('n\'exécute pas une règle désactivée et ne touche pas à ses anomalies', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);
    $this->control($order);
    ControlRuleSetting::create(['code' => 'DAT-02', 'is_enabled' => false]);

    $this->saveOrder(['declared_duration' => 5], $order);
    $run = $this->control($order);

    expect($run->anomalies_closed)->toBe(0)
        ->and(Anomaly::firstOrFail()->state)->toBe(AnomalyState::Proposed);

    $other = $this->saveOrder(['reference' => 'OM-2026-002', 'declared_duration' => 2]);
    expect($this->control($other)->anomalies_created)->toBe(0);
});

it('repère un OM modifié depuis son dernier contrôle, même par ses seules listes', function () {
    $order = $this->saveOrder();
    $this->control($order);
    $this->travel(1)->minutes();

    $this->saveOrder(['employees' => [$this->member($this->rakoto, ['declared_per_diem' => 500000])]], $order);

    expect(MissionOrder::findOrFail($order->id)->changedSinceControl())->toBeTrue();
});
