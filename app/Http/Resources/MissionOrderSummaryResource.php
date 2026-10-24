<?php

namespace App\Http\Resources;

use App\Models\MissionOrder;
use App\Models\MissionOrderEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la liste des OM.
 *
 * @mixin MissionOrder
 */
class MissionOrderSummaryResource extends JsonResource
{
    public const RELATIONS = ['missionObject', 'destinationPlace', 'validator', 'employees.employee'];

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
            'mission_object' => $this->missionObject?->only(['id', 'label']),
            'departure_at' => $this->departure_at?->format('Y-m-d\TH:i'),
            'return_at' => $this->return_at?->format('Y-m-d\TH:i'),
            'destination_place' => $this->destinationPlace?->only(['id', 'name']),
            'validator' => MissionOrderResource::employee($this->validator),
            'employees' => $this->employees->map(fn (MissionOrderEmployee $member) => MissionOrderResource::employee($member->employee))->values(),
            'administrative_status' => $this->administrative_status->value,
            'administrative_status_label' => $this->administrative_status->label(),
            'control_status' => $this->control_status->value,
            'control_status_label' => $this->control_status->label(),
            'changed_since_control' => $this->changedSinceControl(),
            'open_anomalies_count' => $this->whenCounted('openAnomalies'),
        ];
    }
}
