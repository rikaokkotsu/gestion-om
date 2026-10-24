<?php

namespace App\Reports;

use App\Enums\AnomalyState;
use App\Http\Resources\MissionOrderResource;
use App\Models\Anomaly;
use App\Models\AnomalyEvent;
use App\Models\MissionOrder;
use App\Models\MissionOrderEmployee;
use App\Models\User;
use App\Models\Zone;
use App\Support\MissionDuration;
use Illuminate\Support\Collection;

/**
 * Rapport de contrôle d'un OM : sa fiche telle qu'enregistrée, les calculs
 * de l'application à côté des montants déclarés, et ses anomalies avec leur
 * traitement.
 */
final class MissionOrderReport
{
    /**
     * @return array<string, mixed>
     */
    public static function data(MissionOrder $order, ?User $actor): array
    {
        $order->load([...MissionOrderResource::RELATIONS, 'anomalies.employee', 'anomalies.events.user']);

        /** @var Collection<int, Anomaly> $anomalies */
        $anomalies = $order->anomalies->sortBy([
            fn (Anomaly $a, Anomaly $b) => self::stateRank($a) <=> self::stateRank($b),
            fn (Anomaly $a, Anomaly $b) => self::priorityRank($a) <=> self::priorityRank($b),
            fn (Anomaly $a, Anomaly $b) => $a->id <=> $b->id,
        ])->values();

        $members = $order->employees;
        $complete = $members->isNotEmpty() && $members->every(fn (MissionOrderEmployee $member) => $member->calculation_complete === true);
        // Écart cumulé des seuls missionnaires dont le per diem est à la fois déclaré et calculé.
        $compared = $members->filter(fn (MissionOrderEmployee $member) => $member->calculation_complete === true && $member->declared_per_diem !== null);

        return [
            'order' => $order,
            'actor' => $actor,
            'printedAt' => now(),
            'durationConvention' => MissionDuration::label(),
            'zones' => Zone::query()->pluck('label', 'code')->all(),
            'members' => $members,
            'declaredTotal' => $members->whereNotNull('declared_per_diem')->isEmpty() ? null : (int) $members->sum('declared_per_diem'),
            'calculatedTotal' => $complete ? (int) $members->sum('calculated_per_diem') : null,
            'gapTotal' => $compared->isEmpty() ? null : (int) $compared->sum(fn (MissionOrderEmployee $member) => $member->declared_per_diem - $member->calculated_per_diem),
            'costsTotal' => $order->costs->whereNotNull('amount')->isEmpty() ? null : (int) $order->costs->sum('amount'),
            'groups' => [
                'À traiter' => $anomalies->filter(fn (Anomaly $anomaly) => $anomaly->state->isOpen())->values(),
                'Traitées' => $anomalies->filter(fn (Anomaly $anomaly) => $anomaly->state === AnomalyState::Closed && $anomaly->confirmed_at !== null)->values(),
                'Écartées, ou closes par le contrôle sans confirmation' => $anomalies->filter(fn (Anomaly $anomaly) => $anomaly->state === AnomalyState::Dismissed
                    || ($anomaly->state === AnomalyState::Closed && $anomaly->confirmed_at === null))->values(),
            ],
            'lastComment' => fn (Anomaly $anomaly): ?AnomalyEvent => $anomaly->events->whereNotNull('comment')->last(),
        ];
    }

    private static function stateRank(Anomaly $anomaly): int
    {
        return match ($anomaly->state) {
            AnomalyState::Proposed => 0,
            AnomalyState::Confirmed => 1,
            AnomalyState::Transmitted => 2,
            AnomalyState::Closed => 3,
            AnomalyState::Dismissed => 4,
        };
    }

    private static function priorityRank(Anomaly $anomaly): int
    {
        return match ($anomaly->priority->value) {
            'HIGH' => 0,
            'MEDIUM' => 1,
            default => 2,
        };
    }
}
