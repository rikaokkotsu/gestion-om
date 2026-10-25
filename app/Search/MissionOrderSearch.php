<?php

namespace App\Search;

use App\Enums\AdministrativeStatus;
use App\Enums\ControlStatus;
use App\Enums\MissionOrderType;
use App\Models\Category;
use App\Models\Employee;
use App\Models\MissionObject;
use App\Models\MissionOrder;
use App\Models\Place;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Critères de recherche des OM (§ 14.1), combinables entre eux. La liste, le
 * tableau de bord, les exports et le récapitulatif s'en servent tous : un
 * même filtre retient partout les mêmes OM. Les OM supprimés n'y figurent pas.
 */
final class MissionOrderSearch
{
    /**
     * Présence d'anomalies : à traiter, retenues (à traiter ou traitées), aucune retenue.
     */
    public const ANOMALIES = [
        'open' => 'Avec anomalies à traiter',
        'any' => 'Avec anomalies, à traiter ou traitées',
        'none' => 'Sans anomalie',
    ];

    /**
     * Libellés des critères, pour les messages d'erreur et l'en-tête des rapports.
     */
    private const ATTRIBUTES = [
        'search' => 'référence ou missionnaire',
        'missionary' => 'missionnaire',
        'validator' => 'validateur',
        'type' => 'type',
        'mission_object_id' => 'objet',
        'category_id' => 'catégorie',
        'service_id' => 'service',
        'destination_place_id' => 'destination',
        'departure_from' => 'début de la période de départ',
        'departure_to' => 'fin de la période de départ',
        'return_from' => 'début de la période de retour',
        'return_to' => 'fin de la période de retour',
        'administrative_status' => 'état de validation',
        'control_status' => 'contrôle',
        'anomalies' => 'anomalies',
        'employee_id' => 'employé',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'missionary' => ['nullable', 'string', 'max:255'],
            'validator' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(MissionOrderType::class)],
            'mission_object_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'destination_place_id' => ['nullable', 'integer'],
            'departure_from' => ['nullable', 'date'],
            'departure_to' => ['nullable', 'date', 'after_or_equal:departure_from'],
            'return_from' => ['nullable', 'date'],
            'return_to' => ['nullable', 'date', 'after_or_equal:return_from'],
            'administrative_status' => ['nullable', Rule::enum(AdministrativeStatus::class)],
            'control_status' => ['nullable', Rule::enum(ControlStatus::class)],
            'anomalies' => ['nullable', Rule::in(array_keys(self::ANOMALIES))],
            'employee_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Critères de la requête, sans les valeurs vides.
     *
     * @return array<string, string>
     */
    public static function validate(Request $request): array
    {
        $filters = $request->validate(self::rules(), [
            'departure_to.after_or_equal' => 'La période de départ est inversée : sa fin précède son début.',
            'return_to.after_or_equal' => 'La période de retour est inversée : sa fin précède son début.',
        ], self::ATTRIBUTES);

        return array_map(strval(...), array_filter(
            array_intersect_key($filters, self::ATTRIBUTES),
            fn ($value) => $value !== null && $value !== '',
        ));
    }

    /**
     * OM retenus, sans ordre : à chaque usage de choisir le sien.
     *
     * @param  array<string, string>  $filters
     * @return Builder<MissionOrder>
     */
    public static function query(array $filters): Builder
    {
        $member = array_intersect_key($filters, array_flip(['missionary', 'category_id', 'service_id']));

        return MissionOrder::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', self::like($search))
                ->orWhereHas('employees.employee', fn (Builder $employee) => $employee->search($search))))
            // Les critères propres au missionnaire visent une même personne de l'OM.
            ->when($member !== [], fn (Builder $query) => $query->whereHas('employees', fn (Builder $query) => $query
                ->when($member['category_id'] ?? null, fn (Builder $query, string $id) => $query->where('category_id', (int) $id))
                ->when(isset($member['missionary']) || isset($member['service_id']), fn (Builder $query) => $query->whereHas('employee', fn (Builder $employee) => $employee
                    ->when($member['missionary'] ?? null, fn (Builder $employee, string $term) => $employee->search($term))
                    ->when($member['service_id'] ?? null, fn (Builder $employee, string $id) => $employee->where('service_id', (int) $id))))))
            ->when($filters['validator'] ?? null, fn (Builder $query, string $term) => $query->whereHas('validator', fn (Builder $employee) => $employee->search($term)))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['mission_object_id'] ?? null, fn (Builder $query, string $id) => $query->where('mission_object_id', (int) $id))
            ->when($filters['destination_place_id'] ?? null, fn (Builder $query, string $id) => $query->where('destination_place_id', (int) $id))
            ->when($filters['departure_from'] ?? null, fn (Builder $query, string $date) => $query->where('departure_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['departure_to'] ?? null, fn (Builder $query, string $date) => $query->where('departure_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['return_from'] ?? null, fn (Builder $query, string $date) => $query->where('return_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['return_to'] ?? null, fn (Builder $query, string $date) => $query->where('return_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['administrative_status'] ?? null, fn (Builder $query, string $status) => $query->where('administrative_status', $status))
            ->when($filters['control_status'] ?? null, fn (Builder $query, string $status) => $query->where('control_status', $status))
            ->when($filters['anomalies'] ?? null, fn (Builder $query, string $presence) => match ($presence) {
                'open' => $query->whereHas('anomalies', fn (Builder $anomaly) => $anomaly->open()),
                'any' => $query->whereHas('anomalies', fn (Builder $anomaly) => $anomaly->retained()),
                default => $query->whereDoesntHave('anomalies', fn (Builder $anomaly) => $anomaly->retained()),
            })
            ->when($filters['employee_id'] ?? null, fn (Builder $query, string $id) => $query->concerning((int) $id));
    }

    /**
     * Critères en clair, pour l'en-tête des exports et des rapports.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public static function describe(array $filters): array
    {
        $quoted = fn (string $key) => isset($filters[$key]) ? "« {$filters[$key]} »" : null;
        $found = fn (string $key, callable $find) => isset($filters[$key]) ? ($find($filters[$key]) ?? 'introuvable') : null;

        $values = [
            'Référence ou missionnaire' => $quoted('search'),
            'Missionnaire' => $quoted('missionary'),
            'Catégorie' => $found('category_id', fn (string $id) => Category::query()->find((int) $id)?->code),
            'Service' => $found('service_id', fn (string $id) => Service::query()->find((int) $id)?->name),
            'Validateur' => $quoted('validator'),
            'Employé concerné' => $found('employee_id', fn (string $id) => Employee::query()->find((int) $id)?->auditLabel()),
            'Type' => $found('type', fn (string $type) => MissionOrderType::tryFrom($type)?->label()),
            'Objet' => $found('mission_object_id', fn (string $id) => MissionObject::query()->find((int) $id)?->label),
            'Destination' => $found('destination_place_id', fn (string $id) => Place::query()->find((int) $id)?->name),
            'Départ' => self::period($filters['departure_from'] ?? null, $filters['departure_to'] ?? null),
            'Retour' => self::period($filters['return_from'] ?? null, $filters['return_to'] ?? null),
            'État de validation' => $found('administrative_status', fn (string $status) => AdministrativeStatus::tryFrom($status)?->label()),
            'Contrôle' => $found('control_status', fn (string $status) => ControlStatus::tryFrom($status)?->label()),
            'Anomalies' => $found('anomalies', fn (string $presence) => self::ANOMALIES[$presence] ?? null),
        ];

        $lines = [];
        foreach ($values as $label => $value) {
            if ($value !== null) {
                $lines[] = "{$label} : {$value}";
            }
        }

        return $lines;
    }

    /**
     * « du 01/10/2026 au 31/10/2026 », « à partir du 01/10/2026 », « jusqu'au 31/10/2026 ».
     */
    public static function period(?string $from, ?string $to): ?string
    {
        $from = $from === null ? null : Carbon::parse($from)->format('d/m/Y');
        $to = $to === null ? null : Carbon::parse($to)->format('d/m/Y');

        return match (true) {
            $from !== null && $to !== null => "du {$from} au {$to}",
            $from !== null => "à partir du {$from}",
            $to !== null => "jusqu'au {$to}",
            default => null,
        };
    }

    private static function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
    }
}
