<?php

use App\Models\Anomaly;
use App\Models\Category;
use App\Models\MissionObject;
use App\Models\Service;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
    $logistique = Service::create(['name' => 'Logistique']);
    $this->randria = $this->employee('C014', 'Randria', 'Lova', '5A', ['service_id' => $logistique->id]);

    // A : OM de référence, contrôlé sans anomalie.
    $this->a = $this->saveOrder();
    $this->control($this->a);

    // B : en attente, vers Toamasina, deux missionnaires ; une anomalie à traiter.
    $this->b = $this->saveOrder([
        'reference' => 'OM-2026-002',
        'mission_object_id' => MissionObject::where('label', 'Intérim caisse')->value('id'),
        'departure_at' => '2026-12-01 08:00',
        'return_at' => '2026-12-05 17:00',
        'destination_place_id' => $this->place('Toamasina'),
        'declared_duration' => 4,
        'validator_id' => null,
        'validated_at' => null,
        'administrative_status' => 'PENDING',
        'legs' => [],
        'employees' => [$this->member($this->rakoto), $this->member($this->randria, ['category_id' => $this->randria->category_id])],
    ]);
    $this->control($this->b);

    // C : anomalie écartée ; D : anomalie confirmée puis clôturée.
    $this->c = $this->saveOrder(['reference' => 'OM-2026-003', 'departure_at' => '2026-11-10 08:00', 'return_at' => '2026-11-14 17:00', 'declared_duration' => 4]);
    $this->control($this->c);
    $this->postJson('/api/anomalies/'.Anomaly::where('mission_order_id', $this->c->id)->value('id').'/transition', ['state' => 'DISMISSED', 'comment' => 'Convention particulière.'])->assertOk();

    $this->d = $this->saveOrder(['reference' => 'OM-2026-004', 'departure_at' => '2026-11-20 08:00', 'return_at' => '2026-11-24 17:00', 'declared_duration' => 4]);
    $this->control($this->d);
    $anomaly = Anomaly::where('mission_order_id', $this->d->id)->value('id');
    $this->postJson("/api/anomalies/{$anomaly}/transition", ['state' => 'CONFIRMED'])->assertOk();
    $this->postJson("/api/anomalies/{$anomaly}/transition", ['state' => 'CLOSED'])->assertOk();
});

/**
 * Références retenues, triées.
 *
 * @return list<string>
 */
function references(string $query): array
{
    return collect(test()->getJson("/api/mission-orders?{$query}")->assertOk()->json('data'))->pluck('reference')->sort()->values()->all();
}

it('cherche les OM selon la présence d\'anomalies', function () {
    expect(references('anomalies=open'))->toBe(['OM-2026-002'])
        ->and(references('anomalies=any'))->toBe(['OM-2026-002', 'OM-2026-004'])
        ->and(references('anomalies=none'))->toBe(['OM-2026-001', 'OM-2026-003']);
});

it('cherche les OM sur chacun des critères du cahier des charges', function () {
    $category = Category::where('code', '5A')->value('id');
    $service = $this->randria->service_id;
    $object = $this->b->mission_object_id;
    $toamasina = $this->place('Toamasina');

    expect(references('search=OM-2026-00'))->toHaveCount(4)
        ->and(references('search=002'))->toBe(['OM-2026-002'])
        ->and(references('type=MISSION'))->toHaveCount(4)
        ->and(references('type=AFFECTATION'))->toBe([])
        ->and(references("mission_object_id={$object}"))->toBe(['OM-2026-002'])
        ->and(references('missionary=randria'))->toBe(['OM-2026-002'])
        ->and(references('missionary=C014'))->toBe(['OM-2026-002'])
        ->and(references('missionary=M001'))->toHaveCount(4)
        ->and(references("category_id={$category}"))->toBe(['OM-2026-002'])
        ->and(references("service_id={$service}"))->toBe(['OM-2026-002'])
        ->and(references('departure_from=2026-12-01'))->toBe(['OM-2026-002'])
        ->and(references('departure_from=2026-11-05&departure_to=2026-11-30'))->toBe(['OM-2026-003', 'OM-2026-004'])
        ->and(references('return_to=2026-11-14'))->toBe(['OM-2026-001', 'OM-2026-003'])
        ->and(references("destination_place_id={$toamasina}"))->toBe(['OM-2026-002'])
        ->and(references('validator=rabe'))->toBe(['OM-2026-001', 'OM-2026-003', 'OM-2026-004'])
        ->and(references('validator=M002'))->toHaveCount(3)
        ->and(references('administrative_status=PENDING'))->toBe(['OM-2026-002'])
        ->and(references('control_status=NO_ANOMALY'))->toBe(['OM-2026-001', 'OM-2026-003']);
});

it('combine les critères', function () {
    expect(references('missionary=rakoto&anomalies=any&departure_to=2026-11-30'))->toBe(['OM-2026-004'])
        ->and(references('validator=rabe&anomalies=none'))->toBe(['OM-2026-001', 'OM-2026-003'])
        ->and(references('search=OM-2026&administrative_status=VALIDATED&departure_from=2026-11-15'))->toBe(['OM-2026-004']);
});

it('applique les critères du missionnaire à une même personne de l\'OM', function () {
    $category = Category::where('code', '5A')->value('id');

    // Rakoto et une catégorie 5A figurent sur l'OM B, mais pas sur la même personne.
    expect(references("missionary=rakoto&category_id={$category}"))->toBe([])
        ->and(references("missionary=randria&category_id={$category}&service_id={$this->randria->service_id}"))->toBe(['OM-2026-002']);
});

it('n\'inclut pas les OM supprimés', function () {
    $this->deleteJson("/api/mission-orders/{$this->b->id}")->assertNoContent();

    expect(references('anomalies=open'))->toBe([])
        ->and(references(''))->toHaveCount(3);
});

it('refuse une période inversée ou un critère inconnu', function () {
    $this->getJson('/api/mission-orders?departure_from=2026-12-01&departure_to=2026-11-01')
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure_to.0', 'La période de départ est inversée : sa fin précède son début.');
    $this->getJson('/api/mission-orders?return_from=2026-12-01&return_to=2026-11-01')->assertJsonValidationErrors('return_to');
    $this->getJson('/api/mission-orders?anomalies=peut-etre')->assertJsonValidationErrors('anomalies');
});
