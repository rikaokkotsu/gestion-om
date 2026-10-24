<?php

namespace App\Http\Resources;

use App\Models\AssignmentHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssignmentHistory
 */
class AssignmentHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_place' => $this->previousPlace?->only(['id', 'name']),
            'new_place' => $this->newPlace?->only(['id', 'name']),
            'effective_date' => $this->effective_date?->toDateString(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'mission_order_id' => $this->mission_order_id,
            'recorded_by' => $this->recordedBy?->only(['id', 'name']),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
