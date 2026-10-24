<?php

namespace App\Reports;

use App\Models\MissionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Récapitulatif d'un ensemble d'OM (une période, le plus souvent) : les
 * indicateurs du tableau de bord, puis la liste des OM retenus.
 */
final class SummaryReport
{
    /**
     * Nombre d'OM au-delà duquel la liste s'arrête ; l'export Excel n'a pas de limite.
     */
    public const LIST_LIMIT = 500;

    /**
     * @param  Builder<MissionOrder>  $orders
     * @param  list<string>  $criteria
     * @return array<string, mixed>
     */
    public static function data(Builder $orders, array $criteria, ?User $actor): array
    {
        return [
            'figures' => Dashboard::figures($orders),
            'criteria' => $criteria,
            'orders' => (clone $orders)
                ->with(['destinationPlace', 'employees.employee'])
                ->withCount('openAnomalies')
                ->latestDeparture()
                ->limit(self::LIST_LIMIT)
                ->get(),
            'actor' => $actor,
            'printedAt' => now(),
        ];
    }
}
