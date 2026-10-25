<?php

use App\Models\Anomaly;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
    $this->randria = $this->employee('C014', 'Randria', 'Lova', '5A');

    // OM de référence : 503 000 Ar déclarés et calculés, 1 250 000 Ar de frais.
    $this->a = $this->saveOrder();
    $this->control($this->a);

    // Per diem 5A calculé à 321 500 Ar, déclaré à 400 000 Ar ; en attente de validation.
    $this->b = $this->saveOrder([
        'reference' => 'OM-2026-002',
        'departure_at' => '2026-12-01 08:00',
        'return_at' => '2026-12-05 17:00',
        'administrative_status' => 'PENDING',
        'validated_at' => null,
        'additional_costs' => 250000,
        'costs' => [],
        'employees' => [$this->member($this->randria, ['declared_per_diem' => 400000])],
    ]);
    $this->control($this->b);

    // Sans catégorie : le calcul est incomplet, son total partiel ne compte pas.
    $this->c = $this->saveOrder([
        'reference' => 'OM-2026-003',
        'type' => 'AFFECTATION',
        'departure_at' => '2026-12-10 08:00',
        'return_at' => '2026-12-14 17:00',
        'additional_costs' => null,
        'costs' => [],
        'assignment_allowance' => 300000,
        'declared_duration' => 4,
        'employees' => [$this->member($this->rabe, ['category_id' => null, 'declared_per_diem' => 100000])],
    ]);
    $this->control($this->c);
});

it('calcule les indicateurs du tableau de bord sur les OM enregistrés', function () {
    $response = $this->getJson('/api/dashboard')->assertOk();
    $retained = Anomaly::query()->retained()->get();

    expect($response->json('data.orders.total'))->toBe(3)
        ->and($response->json('data.orders.by_type'))->toBe([
            ['value' => 'MISSION', 'label' => 'Mission', 'count' => 2],
            ['value' => 'AFFECTATION', 'label' => 'Affectation', 'count' => 1],
        ])
        ->and(collect($response->json('data.orders.by_administrative_status'))->pluck('count', 'value')->all())
        ->toBe(['PENDING' => 1, 'VALIDATED' => 2, 'REJECTED' => 0, 'CANCELLED' => 0])
        ->and($response->json('data.orders.with_anomalies'))->toBe($retained->pluck('mission_order_id')->unique()->count())
        ->and($response->json('data.anomalies.total'))->toBe($retained->count())
        ->and(array_sum(array_column($response->json('data.anomalies.by_category'), 'count')))->toBe($retained->count())
        ->and($response->json('data.anomalies.by_category'))->toHaveCount(8)
        ->and($response->json('data.per_diem'))->toMatchArray([
            'missionaries' => 3,
            'complete' => 2,
            'incomplete' => 1,
            'not_calculated' => 0,
            'calculated' => 503000 + 321500,
            'declared' => 503000 + 400000 + 100000,
            'declared_count' => 3,
            'compared' => 2,
            'mismatches' => 1,
            'gap' => 400000 - 321500,
        ])
        ->and($response->json('data.costs'))->toBe([
            'orders_with_costs' => 2,
            'additional_costs' => 1500000,
            'orders_with_allowance' => 1,
            'assignment_allowance' => 300000,
        ])
        ->and($response->json('criteria'))->toBe([]);
});

it('compte les OM présentant des anomalies, hors anomalies écartées', function () {
    $anomalies = Anomaly::where('mission_order_id', $this->c->id)->pluck('id');
    foreach ($anomalies as $id) {
        $this->postJson("/api/anomalies/{$id}/transition", ['state' => 'DISMISSED', 'comment' => 'Vu avec la DRH.'])->assertOk();
    }

    $response = $this->getJson('/api/dashboard?type=AFFECTATION')->assertOk();

    expect($response->json('data.orders.total'))->toBe(1)
        ->and($response->json('data.orders.with_anomalies'))->toBe(0)
        ->and($response->json('data.anomalies.total'))->toBe(0)
        ->and($response->json('criteria'))->toBe(['Type : Affectation']);
});

it('limite les indicateurs à une période et ignore les OM supprimés', function () {
    $this->deleteJson("/api/mission-orders/{$this->c->id}")->assertNoContent();

    $response = $this->getJson('/api/dashboard?departure_from=2026-12-01&departure_to=2026-12-31')->assertOk();

    expect($response->json('data.orders.total'))->toBe(1)
        ->and($response->json('data.per_diem.calculated'))->toBe(321500)
        ->and($response->json('data.per_diem.incomplete'))->toBe(0)
        ->and($response->json('data.anomalies.total'))->toBe(Anomaly::query()->retained()->where('mission_order_id', $this->b->id)->count())
        ->and($response->json('criteria'))->toBe(['Départ : du 01/12/2026 au 31/12/2026']);

    $this->getJson('/api/dashboard?departure_from=2026-12-31&departure_to=2026-12-01')->assertJsonValidationErrors('departure_to');
});

it('signale un type non renseigné à part', function () {
    $this->saveOrder(['reference' => 'OM-2026-009', 'type' => null]);

    expect($this->getJson('/api/dashboard')->json('data.orders.by_type.2'))
        ->toBe(['value' => null, 'label' => 'Non renseigné', 'count' => 1]);
});
