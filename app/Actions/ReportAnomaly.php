<?php

namespace App\Actions;

use App\Control\ControlStatusResolver;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyEventType;
use App\Enums\AnomalyNature;
use App\Enums\AnomalyPriority;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Models\Anomaly;
use App\Models\MissionOrder;
use App\Models\MissionOrderEmployee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Signalement manuel d'une anomalie par un contrôleur (§ 3.5). Il est
 * confirmé d'emblée : le contrôleur en est l'auteur. L'OM n'est pas modifié.
 */
class ReportAnomaly
{
    public function __construct(private readonly ControlStatusResolver $statuses) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function __invoke(MissionOrder $order, array $data, ?User $actor): Anomaly
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $member = isset($data['member_id']) ? MissionOrderEmployee::query()->findOrFail($data['member_id']) : null;
            $nature = isset($data['nature']) ? AnomalyNature::from($data['nature']) : null;
            $now = now();

            $anomaly = Anomaly::create([
                'mission_order_id' => $order->id,
                'mission_order_employee_id' => $member?->id,
                'employee_id' => $member?->employee_id,
                'category' => AnomalyCategory::from($data['category']),
                'rule_code' => $data['rule_code'] ?? null,
                'source' => AnomalySource::Manual,
                'nature' => $nature,
                'priority' => isset($data['priority']) ? AnomalyPriority::from($data['priority']) : ($nature?->defaultPriority() ?? AnomalyPriority::Medium),
                'state' => AnomalyState::Confirmed,
                'description' => $data['description'],
                'reported_by_id' => $actor?->id,
                'detected_at' => $now,
                'confirmed_at' => $now,
            ]);

            $anomaly->events()->create([
                'user_id' => $actor?->id,
                'type' => AnomalyEventType::Created,
                'to_state' => AnomalyState::Confirmed,
                'comment' => $data['comment'] ?? null,
            ]);
            Audit::record('anomaly.created', $anomaly, [], [
                'mission_order_id' => $order->id,
                'category' => $anomaly->category->value,
                'priority' => $anomaly->priority->value,
                'description' => $anomaly->description,
            ], $actor);

            $this->statuses->refresh($order);

            return $anomaly;
        });
    }
}
