<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matricule' => $this->matricule,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'position' => $this->position,
            'service_id' => $this->service_id,
            'category_id' => $this->category_id,
            'current_place_id' => $this->current_place_id,
            'service' => $this->whenLoaded('service', fn () => $this->service?->only(['id', 'name'])),
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'code', 'label'])),
            'current_place' => $this->whenLoaded('currentPlace', fn () => $this->currentPlace === null ? null : [
                'id' => $this->currentPlace->id,
                'name' => $this->currentPlace->name,
                'zone' => $this->currentPlace->zone?->only(['code', 'label']),
            ]),
            'account_number' => $this->account_number,
            'is_validator' => $this->is_validator,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'assignment_histories' => AssignmentHistoryResource::collection($this->whenLoaded('assignmentHistories')),
        ];
    }
}
