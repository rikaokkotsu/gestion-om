<?php

namespace App\Reports;

use App\Enums\AdministrativeStatus;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyState;
use App\Enums\ControlStatus;
use App\Enums\MissionOrderType;
use App\Models\Anomaly;
use App\Models\MissionOrder;
use App\Models\MissionOrderEmployee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicateurs du tableau de bord (§ 14.3), calculés à la demande sur les OM
 * enregistrés qu'une recherche retient. Un per diem au calcul incomplet
 * n'entre pas dans le total calculé : un tarif manquant n'est jamais compté
 * comme nul, il est dénombré à part.
 */
final class Dashboard
{
    /**
     * @param  Builder<MissionOrder>  $orders
     * @return array<string, mixed>
     */
    public static function figures(Builder $orders): array
    {
        $orders = (clone $orders)->reorder();
        $ids = (clone $orders)->select('mission_orders.id');
        $retained = Anomaly::query()->whereIn('mission_order_id', $ids)->retained();

        $members = MissionOrderEmployee::query()->whereIn('mission_order_id', $ids)->toBase()->selectRaw(<<<'SQL'
            count(*) as missionaries,
            count(*) filter (where calculation_complete) as complete,
            count(*) filter (where calculation_complete = false) as incomplete,
            count(*) filter (where calculation_complete is null) as not_calculated,
            coalesce(sum(calculated_per_diem) filter (where calculation_complete), 0) as calculated,
            count(declared_per_diem) as declared_count,
            coalesce(sum(declared_per_diem), 0) as declared,
            count(*) filter (where calculation_complete and declared_per_diem is not null) as compared,
            count(*) filter (where calculation_complete and declared_per_diem <> calculated_per_diem) as mismatches,
            coalesce(sum(declared_per_diem - calculated_per_diem) filter (where calculation_complete), 0) as gap
            SQL)->first();

        $amounts = (clone $orders)->toBase()->selectRaw(<<<'SQL'
            count(additional_costs) as orders_with_costs,
            coalesce(sum(additional_costs), 0) as additional_costs,
            count(assignment_allowance) as orders_with_allowance,
            coalesce(sum(assignment_allowance), 0) as assignment_allowance
            SQL)->first();

        $byCategory = (clone $retained)->toBase()
            ->select('category')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where state in (?, ?, ?)) as open', AnomalyState::openValues())
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return [
            'orders' => [
                'total' => (clone $orders)->count(),
                'by_type' => self::breakdown($orders, 'type', MissionOrderType::cases()),
                'by_administrative_status' => self::breakdown($orders, 'administrative_status', AdministrativeStatus::cases()),
                'by_control_status' => self::breakdown($orders, 'control_status', ControlStatus::cases()),
                'with_anomalies' => (clone $orders)->whereHas('anomalies', fn (Builder $anomaly) => $anomaly->retained())->count(),
                'with_open_anomalies' => (clone $orders)->whereHas('anomalies', fn (Builder $anomaly) => $anomaly->open())->count(),
            ],
            'anomalies' => [
                'total' => (int) $byCategory->sum('total'),
                'open' => (int) $byCategory->sum('open'),
                'by_category' => array_map(fn (AnomalyCategory $category) => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'count' => (int) ($byCategory->get($category->value)->total ?? 0),
                    'open' => (int) ($byCategory->get($category->value)->open ?? 0),
                ], AnomalyCategory::cases()),
            ],
            'per_diem' => array_map(intval(...), (array) $members),
            'costs' => array_map(intval(...), (array) $amounts),
        ];
    }

    /**
     * Décompte par valeur, dans l'ordre de l'énumération ; une valeur non
     * renseignée apparaît à la fin quand des OM la laissent vide.
     *
     * @param  Builder<MissionOrder>  $orders
     * @param  list<MissionOrderType>|list<AdministrativeStatus>|list<ControlStatus>  $cases
     * @return list<array{value: string|null, label: string, count: int}>
     */
    private static function breakdown(Builder $orders, string $column, array $cases): array
    {
        $counts = (clone $orders)->toBase()
            ->select($column)
            ->selectRaw('count(*) as total')
            ->groupBy($column)
            ->pluck('total', $column);

        $rows = array_map(fn (MissionOrderType|AdministrativeStatus|ControlStatus $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'count' => (int) ($counts[$case->value] ?? 0),
        ], $cases);

        $missing = (int) ($counts[''] ?? 0);
        if ($missing > 0) {
            $rows[] = ['value' => null, 'label' => 'Non renseigné', 'count' => $missing];
        }

        return $rows;
    }
}
