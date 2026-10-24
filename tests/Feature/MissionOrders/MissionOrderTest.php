<?php

use App\Models\AssignmentHistory;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Employee;
use App\Models\MissionObject;
use App\Models\MissionOrder;
use App\Models\Place;
use App\Models\RateVersion;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Zone;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->nord = (int) Zone::where('code', 'NORD')->value('id');
    $this->autres = (int) Zone::where('code', 'AUTRES')->value('id');
    $this->tana = Place::create(['name' => 'Antananarivo', 'zone_id' => $this->autres]);
    $this->diego = Place::create(['name' => 'Antsiranana', 'zone_id' => $this->nord]);
    $this->tamatave = Place::create(['name' => 'Toamasina', 'zone_id' => $this->autres]);

    $this->rakoto = Employee::create([
        'matricule' => 'M001', 'last_name' => 'Rakoto', 'first_name' => 'Jean',
        'category_id' => Category::where('code', 'R_HC3')->value('id'), 'current_place_id' => $this->tana->id,
    ]);
    $this->rabe = Employee::create([
        'matricule' => 'M002', 'last_name' => 'Rabe', 'first_name' => 'Hery',
        'category_id' => Category::where('code', 'DG')->value('id'), 'is_validator' => true,
    ]);
});

/**
 * Quantités de l'exemple du cahier des charges (§ 11.5).
 *
 * @return list<array<string, mixed>>
 */
function referenceQuantities(int $nord, int $autres): array
{
    return [
        ['consumption_type' => 'PETIT_DEJ', 'zone_id' => $nord, 'quantity' => 3],
        ['consumption_type' => 'DEJEUNER', 'zone_id' => $nord, 'quantity' => 2],
        ['consumption_type' => 'DINER', 'zone_id' => $nord, 'quantity' => 2],
        ['consumption_type' => 'NUITEE', 'zone_id' => $nord, 'quantity' => 2],
        ['consumption_type' => 'PETIT_DEJ', 'zone_id' => $autres, 'quantity' => 2],
        ['consumption_type' => 'DEJEUNER', 'zone_id' => $autres, 'quantity' => 2],
        ['consumption_type' => 'DINER', 'zone_id' => $autres, 'quantity' => 2],
        ['consumption_type' => 'NUITEE', 'zone_id' => $autres, 'quantity' => 2],
    ];
}

function omPayload(object $test, array $overrides = []): array
{
    return [
        'reference' => 'OM-2026-001',
        'type' => 'MISSION',
        'mission_object_id' => MissionObject::where('label', 'VPC et relance')->value('id'),
        'departure_at' => '2026-11-03T08:00',
        'return_at' => '2026-11-07T17:30',
        'departure_place_id' => $test->diego->id,
        'destination_place_id' => $test->tana->id,
        'declared_duration' => 5,
        'additional_costs' => 150000,
        'validator_id' => $test->rabe->id,
        'administrative_status' => 'VALIDATED',
        'validated_at' => '2026-10-30T10:00',
        'employees' => [[
            'employee_id' => $test->rakoto->id,
            'category_id' => $test->rakoto->category_id,
            'declared_per_diem' => 500000,
            'quantities' => referenceQuantities($test->nord, $test->autres),
            'totals' => [
                ['consumption_type' => 'PETIT_DEJ', 'total' => 5],
                ['consumption_type' => 'DEJEUNER', 'total' => 4],
                ['consumption_type' => 'DINER', 'total' => 4],
                ['consumption_type' => 'NUITEE', 'total' => 4],
            ],
        ]],
        ...$overrides,
    ];
}

it('enregistre un OM et calcule le per diem à côté du montant déclaré', function () {
    $response = $this->postJson('/api/mission-orders', omPayload($this))
        ->assertCreated()
        ->assertJsonPath('data.reference', 'OM-2026-001')
        ->assertJsonPath('data.type_label', 'Mission')
        ->assertJsonPath('data.departure_at', '2026-11-03T08:00')
        ->assertJsonPath('data.declared_duration', 5)
        ->assertJsonPath('data.calculated_duration', 5)
        ->assertJsonPath('data.administrative_status_label', 'Validé')
        ->assertJsonPath('data.control_status', 'NOT_CONTROLLED')
        ->assertJsonPath('data.employees.0.declared_per_diem', 500000)
        ->assertJsonPath('data.employees.0.calculated_per_diem', 503000)
        ->assertJsonPath('data.employees.0.calculation_complete', true)
        ->assertJsonPath('data.employees.0.calculation.zone_totals.NORD', 323000)
        ->assertJsonPath('data.employees.0.rate_version.label', 'Barème du cahier des charges v1.0')
        ->assertJsonCount(8, 'data.employees.0.quantities')
        ->assertJsonCount(4, 'data.employees.0.totals');

    $order = MissionOrder::findOrFail($response->json('data.id'));
    expect($order->created_by_id)->toBe($this->user->id)
        ->and(RateVersion::whereNull('effective_from')->firstOrFail()->isLocked())->toBeTrue()
        ->and(AuditLog::where('action', 'mission_order.created')->firstOrFail()->new_values)
        ->toMatchArray(['reference' => 'OM-2026-001', 'additional_costs' => 150000]);
});

it('calcule le forfait repas et garde séparées les données de chaque missionnaire', function () {
    $payload = omPayload($this);
    $payload['employees'][] = [
        'employee_id' => $this->rabe->id,
        'category_id' => $this->rabe->category_id,
        'quantities' => [
            ['consumption_type' => 'FORFAIT_REPAS', 'zone_id' => $this->nord, 'quantity' => 3],
            ['consumption_type' => 'NUITEE', 'zone_id' => $this->nord, 'quantity' => 2],
        ],
    ];

    $this->postJson('/api/mission-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.employees.0.calculated_per_diem', 503000)
        ->assertJsonPath('data.employees.1.calculated_per_diem', 3 * 78000 + 2 * 250000)
        ->assertJsonPath('data.employees.1.declared_per_diem', null)
        ->assertJsonPath('data.additional_costs', 150000);
});

it('enregistre un OM incohérent sans le bloquer', function () {
    $payload = omPayload($this, [
        'type' => null,
        'departure_at' => '2026-11-07T08:00',
        'return_at' => '2026-11-03T08:00',
        'declared_duration' => -2,
        'assignment_allowance' => 300000,
        // Validateur parmi les missionnaires, non habilité.
        'validator_id' => $this->rakoto->id,
    ]);
    $payload['employees'][0]['quantities'][0]['quantity'] = -1;

    $this->postJson('/api/mission-orders', $payload)->assertCreated()
        ->assertJsonPath('data.type', null)
        ->assertJsonPath('data.calculated_duration', -3)
        ->assertJsonPath('data.assignment_allowance', 300000)
        ->assertJsonPath('data.validator.is_validator', false)
        ->assertJsonPath('data.employees.0.calculation_complete', false);

    // Même référence : enregistrée, le doublon sera signalé.
    $this->postJson('/api/mission-orders', omPayload($this))->assertCreated();
    expect(MissionOrder::where('reference', 'OM-2026-001')->count())->toBe(2);
});

it('accepte un document presque vide', function () {
    $this->postJson('/api/mission-orders', [])
        ->assertCreated()
        ->assertJsonPath('data.administrative_status', 'PENDING')
        ->assertJsonPath('data.employees', []);
});

it('signale un calcul impossible sans date de départ', function () {
    $this->postJson('/api/mission-orders', omPayload($this, ['departure_at' => null]))
        ->assertCreated()
        ->assertJsonPath('data.calculated_duration', null)
        ->assertJsonPath('data.employees.0.calculation_complete', false)
        ->assertJsonPath('data.employees.0.calculation.issues.0', 'Date de départ manquante : le barème applicable ne peut pas être déterminé.');
});

it('refuse les références inexistantes et les doublons de saisie', function () {
    $payload = omPayload($this);
    $payload['employees'][] = ['employee_id' => $this->rakoto->id];
    $payload['employees'][0]['quantities'][] = ['consumption_type' => 'PETIT_DEJ', 'zone_id' => $this->nord, 'quantity' => 1];

    $this->postJson('/api/mission-orders', [...$payload, 'destination_place_id' => 999999])
        ->assertJsonValidationErrors(['destination_place_id', 'employees.0.employee_id', 'employees.1.employee_id', 'employees.0.quantities']);
});

it('met à jour l\'affectation des missionnaires d\'un OM de type Affectation', function () {
    $payload = omPayload($this, [
        'type' => 'AFFECTATION',
        'mission_object_id' => MissionObject::where('label', 'Affectation')->value('id'),
        'destination_place_id' => $this->tamatave->id,
    ]);
    $id = $this->postJson('/api/mission-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.employees.0.assignment_changes.0.new_place.name', 'Toamasina')
        ->assertJsonPath('data.employees.0.assignment_changes.0.effective_date', '2026-11-03')
        ->json('data.id');

    $history = AssignmentHistory::where('mission_order_id', $id)->sole();
    expect($this->rakoto->refresh()->current_place_id)->toBe($this->tamatave->id)
        ->and($history->previous_place_id)->toBe($this->tana->id)
        ->and($history->source->value)->toBe('om')
        ->and($history->effective_date->toDateString())->toBe('2026-11-03');

    // Un nouvel enregistrement sans changement de destination n'ajoute rien.
    $this->patchJson("/api/mission-orders/{$id}", ['notes' => 'Vérifié'])->assertOk();
    // Un changement de type ne défait pas l'affectation : l'historique garde la trace.
    $this->patchJson("/api/mission-orders/{$id}", ['type' => 'MISSION'])->assertOk();

    expect(AssignmentHistory::where('mission_order_id', $id)->count())->toBe(1)
        ->and($this->rakoto->refresh()->current_place_id)->toBe($this->tamatave->id);
});

it('garde l\'ordre des étapes de transport et la ventilation des frais', function () {
    $service = TransportMode::where('label', 'Voiture de service')->value('id');
    $brousse = TransportMode::where('label', 'Taxi-brousse')->value('id');
    $location = TransportMode::where('label', 'Voiture de location')->value('id');

    $this->postJson('/api/mission-orders', omPayload($this, [
        'legs' => [
            ['direction' => 'OUTBOUND', 'transport_mode_id' => $service, 'from_place_id' => $this->diego->id, 'to_place_id' => $this->tamatave->id],
            ['direction' => 'RETURN', 'transport_mode_id' => $brousse, 'from_place_id' => $this->tana->id, 'to_place_id' => $this->tamatave->id, 'cost' => 40000],
            ['direction' => 'OUTBOUND', 'transport_mode_id' => $brousse, 'from_place_id' => $this->tamatave->id, 'to_place_id' => $this->tana->id, 'cost' => 35000],
            ['direction' => 'RETURN', 'transport_mode_id' => $location, 'from_place_id' => $this->tamatave->id, 'to_place_id' => $this->diego->id],
        ],
        'costs' => [
            ['nature' => 'TRANSPORT', 'amount' => 75000],
            ['nature' => 'HEALTH', 'label' => 'Pharmacie', 'amount' => 20000],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.legs.0.direction', 'OUTBOUND')
        ->assertJsonPath('data.legs.0.position', 1)
        ->assertJsonPath('data.legs.0.transport_mode.label', 'Voiture de service')
        ->assertJsonPath('data.legs.1.position', 2)
        ->assertJsonPath('data.legs.1.transport_mode.generates_costs', true)
        ->assertJsonPath('data.legs.2.direction', 'RETURN')
        ->assertJsonPath('data.legs.2.from_place.name', 'Antananarivo')
        ->assertJsonPath('data.legs.3.to_place.name', 'Antsiranana')
        ->assertJsonPath('data.costs.1.nature_label', 'Services de santé')
        ->assertJsonPath('data.costs.1.label', 'Pharmacie');
});

it('garde le barème retenu tant que la date de départ ne change pas', function () {
    $id = $this->postJson('/api/mission-orders', omPayload($this, [
        'departure_at' => '2027-02-01T08:00',
        'return_at' => '2027-02-05T17:00',
    ]))->assertCreated()->json('data.id');

    // Un barème 2027 arrive ensuite, avec une nuitée R HC3 en zone Nord plus chère.
    $version = $this->postJson('/api/rate-versions', ['label' => 'Barème 2027', 'effective_from' => '2027-01-01'])->json('data.id');
    $this->patchJson("/api/rate-versions/{$version}", ['rates' => [[
        'category_id' => $this->rakoto->category_id, 'zone_id' => $this->nord, 'consumption_type' => 'NUITEE',
        'amount' => 130000, 'is_applicable' => true,
    ]]])->assertOk();

    $this->patchJson("/api/mission-orders/{$id}", ['notes' => 'Relu'])
        ->assertJsonPath('data.employees.0.calculated_per_diem', 503000)
        ->assertJsonPath('data.employees.0.rate_version.label', 'Barème du cahier des charges v1.0');

    $this->patchJson("/api/mission-orders/{$id}", ['departure_at' => '2027-02-02T08:00'])
        ->assertJsonPath('data.employees.0.calculated_per_diem', 523000)
        ->assertJsonPath('data.employees.0.rate_version.label', 'Barème 2027');
    expect(RateVersion::findOrFail($version)->isLocked())->toBeTrue();
});

it('modifie les missionnaires et journalise l\'avant et l\'après', function () {
    $id = $this->postJson('/api/mission-orders', omPayload($this))->json('data.id');
    $first = MissionOrder::findOrFail($id)->employees()->sole();

    $this->patchJson("/api/mission-orders/{$id}", [
        'additional_costs' => 180000,
        'employees' => [
            ['employee_id' => $this->rabe->id, 'category_id' => $this->rabe->category_id],
            ['employee_id' => $this->rakoto->id, 'category_id' => $this->rakoto->category_id, 'declared_per_diem' => 503000],
        ],
    ])->assertOk()
        ->assertJsonPath('data.employees.0.employee.matricule', 'M002')
        ->assertJsonPath('data.employees.1.id', $first->id)
        ->assertJsonPath('data.employees.1.declared_per_diem', 503000)
        // Quantités non transmises : conservées.
        ->assertJsonPath('data.employees.1.calculated_per_diem', 503000);

    $log = AuditLog::where('action', 'mission_order.updated')->sole();
    expect(array_keys($log->new_values))->toEqualCanonicalizing(['additional_costs', 'employees'])
        ->and($log->old_values['additional_costs'])->toBe(150000)
        ->and($log->new_values['additional_costs'])->toBe(180000);

    $this->patchJson("/api/mission-orders/{$id}", ['employees' => [['employee_id' => $this->rabe->id]]])
        ->assertJsonCount(1, 'data.employees');
});

it('supprime un OM logiquement et garde l\'historique d\'affectation', function () {
    $id = $this->postJson('/api/mission-orders', omPayload($this, [
        'type' => 'AFFECTATION', 'destination_place_id' => $this->tamatave->id,
    ]))->json('data.id');

    $this->deleteJson("/api/mission-orders/{$id}")->assertNoContent();

    $this->getJson("/api/mission-orders/{$id}")->assertNotFound();
    $this->getJson('/api/mission-orders')->assertJsonCount(0, 'data');
    expect(MissionOrder::withTrashed()->find($id))->not->toBeNull()
        ->and(AssignmentHistory::where('mission_order_id', $id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'mission_order.deleted')->sole()->old_values['reference'])->toBe('OM-2026-001');
});

it('liste et filtre les OM', function () {
    $this->postJson('/api/mission-orders', omPayload($this))->assertCreated();
    $this->postJson('/api/mission-orders', omPayload($this, [
        'reference' => 'OM-2026-002', 'type' => 'AFFECTATION', 'validator_id' => null,
        'departure_at' => '2026-12-01T08:00', 'administrative_status' => 'PENDING',
        'employees' => [['employee_id' => $this->rabe->id]],
    ]))->assertCreated();

    $this->getJson('/api/mission-orders')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.reference', 'OM-2026-002')
        ->assertJsonPath('data.1.employees.0.matricule', 'M001');
    $this->getJson('/api/mission-orders?search=rakoto')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'OM-2026-001');
    $this->getJson('/api/mission-orders?search=002')->assertJsonCount(1, 'data');
    $this->getJson('/api/mission-orders?type=AFFECTATION')->assertJsonCount(1, 'data');
    $this->getJson('/api/mission-orders?administrative_status=VALIDATED')->assertJsonCount(1, 'data');

    // Rabe est validateur du premier et missionnaire du second.
    $this->getJson("/api/employees/{$this->rabe->id}/mission-orders")->assertJsonCount(2, 'data');
    $this->getJson("/api/employees/{$this->rakoto->id}/mission-orders")->assertJsonCount(1, 'data');
});
