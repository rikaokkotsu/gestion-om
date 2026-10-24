<?php

namespace Tests\Concerns;

use App\Actions\SaveEmployee;
use App\Actions\SaveMissionOrder;
use App\Control\ControlContext;
use App\Control\ControlEngine;
use App\Control\RuleRegistry;
use App\Enums\AssignmentSource;
use App\Models\Category;
use App\Models\ControlRun;
use App\Models\Employee;
use App\Models\MissionObject;
use App\Models\MissionOrder;
use App\Models\Place;
use App\Models\Service;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Zone;

/**
 * Jeu de données du contrôle : un OM de référence sans aucune anomalie,
 * que chaque test altère pour faire apparaître la situation qu'il vérifie.
 */
trait BuildsMissionOrders
{
    protected User $user;

    protected int $nord;

    protected int $autres;

    /**
     * @var array<string, Place>
     */
    protected array $places = [];

    protected Employee $rakoto;

    protected Employee $rabe;

    protected function setUpMissionOrders(): void
    {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->nord = (int) Zone::where('code', 'NORD')->value('id');
        $this->autres = (int) Zone::where('code', 'AUTRES')->value('id');
        foreach (['Antananarivo' => $this->autres, 'Toamasina' => $this->autres, 'Mahajanga' => $this->autres, 'Antsiranana' => $this->nord] as $name => $zone) {
            $this->places[$name] = Place::create(['name' => $name, 'zone_id' => $zone]);
        }
        $this->places['Ambanja'] = Place::create(['name' => 'Ambanja']);
        $service = Service::create(['name' => 'Contrôle de gestion']);

        $this->rakoto = $this->employee('M001', 'Rakoto', 'Jean', 'R_HC3', ['position' => 'Superviseur régional', 'service_id' => $service->id]);
        $this->rabe = $this->employee('M002', 'Rabe', 'Hery', 'DG', ['position' => 'Directeur général', 'service_id' => $service->id, 'is_validator' => true]);
    }

    /**
     * Employé à la fiche complète, affecté à Antananarivo.
     *
     * @param  array<string, mixed>  $values
     */
    protected function employee(string $matricule, string $lastName, string $firstName, string $category, array $values = []): Employee
    {
        return app(SaveEmployee::class)(null, [
            'matricule' => $matricule,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'position' => 'Agent',
            'service_id' => Service::query()->value('id'),
            'category_id' => Category::where('code', $category)->value('id'),
            'current_place_id' => $this->places['Antananarivo']->id,
            'account_number' => '0001'.$matricule,
            ...$values,
        ], AssignmentSource::Manual, $this->user);
    }

    protected function mode(string $label): int
    {
        return (int) TransportMode::where('label', $label)->value('id');
    }

    protected function place(string $name): int
    {
        return $this->places[$name]->id;
    }

    /**
     * Quantités de l'exemple du cahier des charges (§ 11.5).
     *
     * @return list<array<string, mixed>>
     */
    protected function referenceQuantities(): array
    {
        $quantities = [];
        foreach ([[$this->nord, [3, 2, 2, 2]], [$this->autres, [2, 2, 2, 2]]] as [$zone, $values]) {
            foreach (['PETIT_DEJ', 'DEJEUNER', 'DINER', 'NUITEE'] as $index => $type) {
                $quantities[] = ['consumption_type' => $type, 'zone_id' => $zone, 'quantity' => $values[$index]];
            }
        }

        return $quantities;
    }

    /**
     * Missionnaire de l'OM de référence : 503 000 Ar déclarés et calculés.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function member(Employee $employee, array $overrides = []): array
    {
        return [
            'employee_id' => $employee->id,
            'category_id' => $employee->category_id,
            'declared_per_diem' => 503000,
            'quantities' => $this->referenceQuantities(),
            'totals' => [
                ['consumption_type' => 'PETIT_DEJ', 'total' => 5],
                ['consumption_type' => 'DEJEUNER', 'total' => 4],
                ['consumption_type' => 'DINER', 'total' => 4],
                ['consumption_type' => 'NUITEE', 'total' => 4],
            ],
            ...$overrides,
        ];
    }

    /**
     * Données de l'OM de référence : mission de 5 jours d'Antananarivo à
     * Antsiranana en avion, validée, frais ventilés et cohérents.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function cleanOrderData(array $overrides = []): array
    {
        return [
            'reference' => 'OM-2026-001',
            'type' => 'MISSION',
            'mission_object_id' => MissionObject::where('label', 'VPC et relance')->value('id'),
            'departure_at' => '2026-11-03 08:00',
            'return_at' => '2026-11-07 17:30',
            'departure_place_id' => $this->place('Antananarivo'),
            'destination_place_id' => $this->place('Antsiranana'),
            'declared_duration' => 5,
            'additional_costs' => 1250000,
            'assignment_allowance' => null,
            'validator_id' => $this->rabe->id,
            'validated_at' => '2026-10-30 10:00',
            'administrative_status' => 'VALIDATED',
            'costs' => [
                ['nature' => 'TRANSPORT', 'label' => 'Billets d\'avion', 'amount' => 1200000],
                ['nature' => 'HEALTH', 'label' => null, 'amount' => 50000],
            ],
            'legs' => [
                ['direction' => 'OUTBOUND', 'transport_mode_id' => $this->mode('Avion'), 'from_place_id' => $this->place('Antananarivo'), 'to_place_id' => $this->place('Antsiranana'), 'cost' => 600000],
                ['direction' => 'RETURN', 'transport_mode_id' => $this->mode('Avion'), 'from_place_id' => $this->place('Antsiranana'), 'to_place_id' => $this->place('Antananarivo'), 'cost' => 600000],
            ],
            'employees' => [$this->member($this->rakoto)],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function saveOrder(array $overrides = [], ?MissionOrder $order = null): MissionOrder
    {
        return app(SaveMissionOrder::class)($order, $order === null ? $this->cleanOrderData($overrides) : $overrides, $this->user);
    }

    /**
     * Descriptions relevées par une règle sur l'OM, sans rien enregistrer.
     *
     * @return list<string>
     */
    protected function findings(MissionOrder $order, string $code): array
    {
        $rule = app(RuleRegistry::class)->find($code) ?? throw new \InvalidArgumentException("Règle {$code} inconnue.");
        $context = new ControlContext(MissionOrder::query()->findOrFail($order->id), Zone::query()->get()->keyBy('id'));

        $descriptions = [];
        foreach ($rule->check($context) as $finding) {
            $descriptions[] = $finding->description;
        }

        return $descriptions;
    }

    protected function control(MissionOrder $order): ControlRun
    {
        return app(ControlEngine::class)->controlOrder(MissionOrder::query()->findOrFail($order->id), $this->user);
    }
}
