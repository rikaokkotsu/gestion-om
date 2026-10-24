<?php

namespace App\Http\Resources;

use App\Control\RuleRegistry;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Models\Anomaly;
use App\Models\AnomalyEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anomalie avec ses libellés ; l'historique n'est joint que sur la fiche.
 *
 * @mixin Anomaly
 */
class AnomalyResource extends JsonResource
{
    public const RELATIONS = ['missionOrder', 'employee', 'reportedBy'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->missionOrder;

        return [
            'id' => $this->id,
            'mission_order_id' => $this->mission_order_id,
            'mission_order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'type_label' => $order->type?->label(),
                'departure_at' => $order->departure_at?->format('Y-m-d\TH:i'),
                'deleted' => $order->trashed(),
            ],
            'member_id' => $this->mission_order_employee_id,
            'employee' => MissionOrderResource::employee($this->employee),
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'rule_code' => $this->rule_code,
            'rule_label' => $this->rule_code === null ? null : app(RuleRegistry::class)->find($this->rule_code)?->definition()->label,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'nature' => $this->nature?->value,
            'nature_label' => $this->nature?->label(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'transitions' => array_map(fn (AnomalyState $state) => ['value' => $state->value, 'label' => $state->label()], $this->state->transitions()),
            // Un signalement manuel se modifie entièrement ; une anomalie automatique garde la description de sa règle.
            'editable_fields' => $this->source === AnomalySource::Manual ? ['category', 'priority', 'description', 'member_id'] : ['priority'],
            'description' => $this->description,
            'reported_by' => $this->reportedBy?->only(['id', 'name']),
            'control_run_id' => $this->control_run_id,
            'detected_at' => $this->detected_at->toIso8601String(),
            'last_detected_at' => $this->last_detected_at?->toIso8601String(),
            'no_longer_detected_at' => $this->no_longer_detected_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (AnomalyEvent $event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'type_label' => $event->type->label(),
                'user' => $event->user?->only(['id', 'name']),
                'from_state' => $event->from_state?->value,
                'from_state_label' => $event->from_state?->label(),
                'to_state' => $event->to_state?->value,
                'to_state_label' => $event->to_state?->label(),
                'comment' => $event->comment,
                'changes' => $event->changes,
                'created_at' => $event->created_at->toIso8601String(),
            ])->values()),
        ];
    }
}
