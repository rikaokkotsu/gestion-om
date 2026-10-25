<?php

namespace App\Search;

use App\Control\RuleRegistry;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyPriority;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Models\Anomaly;
use App\Models\ControlRun;
use App\Models\Employee;
use App\Models\MissionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Critères de la liste des anomalies (§ 13.4), partagés avec son export. Les
 * anomalies des OM supprimés n'y figurent pas.
 */
final class AnomalySearch
{
    private const ATTRIBUTES = [
        'state' => 'état',
        'category' => 'catégorie',
        'source' => 'origine',
        'priority' => 'priorité',
        'rule_code' => 'règle',
        'from' => 'date de début',
        'to' => 'date de fin',
        'mission_order_id' => 'OM',
        'employee_id' => 'employé',
        'control_run_id' => 'contrôle par lot',
        'search' => 'recherche',
    ];

    public function __construct(private readonly RuleRegistry $registry) {}

    /**
     * Critères de la requête, sans les valeurs vides.
     *
     * @return array<string, string>
     */
    public function validate(Request $request): array
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'all', ...array_column(AnomalyState::cases(), 'value')])],
            'category' => ['nullable', Rule::enum(AnomalyCategory::class)],
            'source' => ['nullable', Rule::enum(AnomalySource::class)],
            'priority' => ['nullable', Rule::enum(AnomalyPriority::class)],
            'rule_code' => ['nullable', Rule::in(array_keys($this->registry->all()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'mission_order_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'control_run_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
        ], ['to.after_or_equal' => 'La période de détection est inversée : sa fin précède son début.'], self::ATTRIBUTES);

        return array_map(strval(...), array_filter(
            array_intersect_key($filters, self::ATTRIBUTES),
            fn ($value) => $value !== null && $value !== '',
        ));
    }

    /**
     * Anomalies retenues, de la plus récente à la plus ancienne.
     *
     * @param  array<string, string>  $filters
     * @return Builder<Anomaly>
     */
    public static function query(array $filters): Builder
    {
        return Anomaly::query()
            ->onActiveOrders()
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => match ($state) {
                'open' => $query->open(),
                'all' => $query,
                default => $query->where('state', $state),
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where('source', $source))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['rule_code'] ?? null, fn (Builder $query, string $code) => $query->where('rule_code', $code))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('detected_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->where('detected_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['mission_order_id'] ?? null, fn (Builder $query, string $id) => $query->where('mission_order_id', (int) $id))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, string $id) => $query->where('employee_id', (int) $id))
            ->when($filters['control_run_id'] ?? null, fn (Builder $query, string $id) => $query->where('control_run_id', (int) $id))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereHas('missionOrder', fn (Builder $order) => $order->where('reference', 'ilike', $like))
                    ->orWhereHas('employee', fn (Builder $employee) => $employee->search($search))
                    ->orWhere('description', 'ilike', $like));
            })
            ->orderByDesc('detected_at')
            ->orderByDesc('id');
    }

    /**
     * Critères en clair, pour l'en-tête de l'export.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function describe(array $filters): array
    {
        $state = $filters['state'] ?? null;
        $values = [
            'État' => match ($state) {
                null, 'all' => null,
                'open' => 'à traiter',
                default => AnomalyState::tryFrom($state)?->label(),
            },
            'Catégorie' => isset($filters['category']) ? AnomalyCategory::tryFrom($filters['category'])?->label() : null,
            'Règle' => isset($filters['rule_code']) ? $filters['rule_code'].' · '.$this->registry->find($filters['rule_code'])?->definition()->label : null,
            'Origine' => isset($filters['source']) ? AnomalySource::tryFrom($filters['source'])?->label() : null,
            'Priorité' => isset($filters['priority']) ? AnomalyPriority::tryFrom($filters['priority'])?->label() : null,
            'Détectée' => MissionOrderSearch::period($filters['from'] ?? null, $filters['to'] ?? null),
            'OM' => isset($filters['mission_order_id']) ? MissionOrder::withTrashed()->find((int) $filters['mission_order_id'])?->auditLabel() ?? 'introuvable' : null,
            'Employé' => isset($filters['employee_id']) ? Employee::query()->find((int) $filters['employee_id'])?->auditLabel() ?? 'introuvable' : null,
            'Contrôle par lot' => isset($filters['control_run_id']) ? ControlRun::query()->find((int) $filters['control_run_id'])?->auditLabel() ?? 'introuvable' : null,
            'Recherche' => isset($filters['search']) ? "« {$filters['search']} »" : null,
        ];

        $lines = [];
        foreach ($values as $label => $value) {
            if ($value !== null) {
                $lines[] = "{$label} : {$value}";
            }
        }

        return $lines;
    }
}
