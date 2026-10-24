<?php

namespace App\Http\Resources;

use App\Models\AssignmentHistory;
use App\Models\ConsumptionQuantity;
use App\Models\ConsumptionTotal;
use App\Models\Employee;
use App\Models\MissionOrder;
use App\Models\MissionOrderCost;
use App\Models\MissionOrderEmployee;
use App\Models\Place;
use App\Models\TransportLeg;
use App\Support\MissionDuration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche complète d'un OM. Les dates et heures sont rendues en heure locale,
 * au format des champs de saisie (AAAA-MM-JJTHH:MM).
 *
 * @mixin MissionOrder
 */
class MissionOrderResource extends JsonResource
{
    public const RELATIONS = [
        'missionObject', 'departurePlace.zone', 'destinationPlace.zone', 'validator', 'createdBy', 'updatedBy',
        'costs', 'legs.transportMode', 'legs.fromPlace', 'legs.toPlace',
        'employees.employee.category', 'employees.employee.currentPlace', 'employees.category', 'employees.rateVersion',
        'employees.quantities', 'employees.totals',
        'assignmentHistories.previousPlace', 'assignmentHistories.newPlace',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'mission_object_id' => $this->mission_object_id,
            'mission_object' => $this->missionObject?->only(['id', 'label']),
            'departure_at' => $this->departure_at?->format('Y-m-d\TH:i'),
            'return_at' => $this->return_at?->format('Y-m-d\TH:i'),
            'departure_place_id' => $this->departure_place_id,
            'departure_place' => self::place($this->departurePlace),
            'destination_place_id' => $this->destination_place_id,
            'destination_place' => self::place($this->destinationPlace),
            'declared_duration' => $this->declared_duration,
            'calculated_duration' => $this->calculatedDuration(),
            'duration_convention' => MissionDuration::label(),
            'additional_costs' => $this->additional_costs,
            'assignment_allowance' => $this->assignment_allowance,
            'validator_id' => $this->validator_id,
            'validator' => self::employee($this->validator),
            'validated_at' => $this->validated_at?->format('Y-m-d\TH:i'),
            'administrative_status' => $this->administrative_status->value,
            'administrative_status_label' => $this->administrative_status->label(),
            'control_status' => $this->control_status->value,
            'control_status_label' => $this->control_status->label(),
            'notes' => $this->notes,
            'costs' => $this->costs->map(fn (MissionOrderCost $cost) => [
                'nature' => $cost->nature->value,
                'nature_label' => $cost->nature->label(),
                'label' => $cost->label,
                'amount' => $cost->amount,
            ])->values(),
            'legs' => $this->legs->map(fn (TransportLeg $leg) => [
                'direction' => $leg->direction->value,
                'position' => $leg->position,
                'transport_mode_id' => $leg->transport_mode_id,
                'transport_mode' => $leg->transportMode?->only(['id', 'label', 'generates_costs']),
                'from_place_id' => $leg->from_place_id,
                'from_place' => $leg->fromPlace?->only(['id', 'name']),
                'to_place_id' => $leg->to_place_id,
                'to_place' => $leg->toPlace?->only(['id', 'name']),
                'cost' => $leg->cost,
            ])->values(),
            'employees' => $this->employees->map(fn (MissionOrderEmployee $member) => [
                'id' => $member->id,
                'employee_id' => $member->employee_id,
                'employee' => [
                    ...self::employee($member->employee) ?? [],
                    'category' => $member->employee->category?->only(['id', 'code', 'label']),
                    'current_place' => $member->employee->currentPlace?->only(['id', 'name']),
                ],
                'category_id' => $member->category_id,
                'category' => $member->category?->only(['id', 'code', 'label']),
                'declared_per_diem' => $member->declared_per_diem,
                'calculated_per_diem' => $member->calculated_per_diem,
                'calculation_complete' => $member->calculation_complete,
                'calculation' => $member->calculation,
                'rate_version' => $member->rateVersion?->only(['id', 'label']),
                'calculated_at' => $member->calculated_at?->toIso8601String(),
                'quantities' => $member->quantities->map(fn (ConsumptionQuantity $quantity) => [
                    'consumption_type' => $quantity->consumption_type->value,
                    'zone_id' => $quantity->zone_id,
                    'quantity' => $quantity->quantity,
                ])->values(),
                'totals' => $member->totals->map(fn (ConsumptionTotal $total) => [
                    'consumption_type' => $total->consumption_type->value,
                    'total' => $total->total,
                ])->values(),
                'assignment_changes' => $this->assignmentHistories
                    ->where('employee_id', $member->employee_id)
                    ->sortBy('id')
                    ->map(fn (AssignmentHistory $history) => [
                        'previous_place' => $history->previousPlace?->only(['id', 'name']),
                        'new_place' => $history->newPlace?->only(['id', 'name']),
                        'effective_date' => $history->effective_date?->toDateString(),
                    ])->values(),
            ])->values(),
            'created_by' => $this->createdBy?->only(['id', 'name']),
            'updated_by' => $this->updatedBy?->only(['id', 'name']),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function place(?Place $place): ?array
    {
        return $place === null ? null : [
            'id' => $place->id,
            'name' => $place->name,
            'zone' => $place->zone?->only(['id', 'code', 'label']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function employee(?Employee $employee): ?array
    {
        return $employee === null ? null : [
            'id' => $employee->id,
            'matricule' => $employee->matricule,
            'last_name' => $employee->last_name,
            'first_name' => $employee->first_name,
            'is_validator' => $employee->is_validator,
            'is_active' => $employee->is_active,
        ];
    }
}
