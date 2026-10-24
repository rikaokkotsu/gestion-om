<?php

namespace App\Http\Resources;

use App\Models\ControlRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ControlRun
 */
class ControlRunResource extends JsonResource
{
    public const RELATIONS = ['missionOrder', 'launchedBy'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger->value,
            'trigger_label' => $this->trigger->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'mission_order' => $this->missionOrder?->only(['id', 'reference']),
            'filters' => $this->filters,
            'launched_by' => $this->launchedBy?->only(['id', 'name']),
            'orders_count' => $this->orders_count,
            'processed_count' => $this->processed_count,
            'anomalies_created' => $this->anomalies_created,
            'anomalies_closed' => $this->anomalies_closed,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
