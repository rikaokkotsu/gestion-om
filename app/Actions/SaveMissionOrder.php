<?php

namespace App\Actions;

use App\Enums\AssignmentSource;
use App\Enums\MissionOrderType;
use App\Models\AssignmentHistory;
use App\Models\ConsumptionQuantity;
use App\Models\ConsumptionTotal;
use App\Models\MissionOrder;
use App\Models\MissionOrderCost;
use App\Models\MissionOrderEmployee;
use App\Models\RateVersion;
use App\Models\TransportLeg;
use App\Models\User;
use App\PerDiem\Consumption;
use App\PerDiem\PerDiemCalculator;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre un OM tel que saisi, puis :
 * - calcule le per diem de chaque missionnaire, à côté du montant déclaré ;
 * - fige la version de barème utilisée ;
 * - met à jour l'affectation des missionnaires d'un OM de type Affectation ;
 * - journalise l'avant et l'après.
 * Aucune incohérence n'empêche l'enregistrement : le contrôle les signale.
 */
class SaveMissionOrder
{
    private const FIELDS = [
        'reference', 'type', 'mission_object_id', 'departure_at', 'return_at', 'departure_place_id', 'destination_place_id',
        'declared_duration', 'additional_costs', 'assignment_allowance', 'validator_id', 'validated_at', 'administrative_status', 'notes',
    ];

    public function __construct(
        private readonly PerDiemCalculator $calculator,
        private readonly SaveEmployee $saveEmployee,
    ) {}

    /**
     * Les listes (frais, étapes, missionnaires) absentes de `$data` ne sont pas modifiées.
     *
     * @param  array<string, mixed>  $data  données validées
     */
    public function __invoke(?MissionOrder $order, array $data, ?User $actor): MissionOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $creating = $order === null;
            $order ??= new MissionOrder;
            $before = $creating ? [] : self::snapshot($order);
            $previousDeparture = $order->departure_at?->toDateString();
            $previousVersionId = $creating ? null : $order->employees()->whereNotNull('rate_version_id')->value('rate_version_id');

            $order->fill(Arr::only($data, self::FIELDS));
            if ($creating) {
                $order->created_by_id = $actor?->id;
            }
            $order->updated_by_id = $actor?->id;
            $order->save();

            if (array_key_exists('costs', $data)) {
                $this->syncCosts($order, $data['costs']);
            }
            if (array_key_exists('legs', $data)) {
                $this->syncLegs($order, $data['legs']);
            }
            if (array_key_exists('employees', $data)) {
                $this->syncEmployees($order, $data['employees']);
            }

            $this->calculatePerDiem($order, $previousDeparture, is_int($previousVersionId) ? $previousVersionId : null);
            $this->applyAssignment($order, $actor);

            $after = self::snapshot($order);
            if ($creating) {
                Audit::record('mission_order.created', $order, [], $after, $actor);
            } else {
                $changed = array_keys(array_filter($after, fn ($value, string $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    Audit::record('mission_order.updated', $order, Arr::only($before, $changed), Arr::only($after, $changed), $actor);
                }
            }

            return $order;
        });
    }

    /**
     * État complet de l'OM pour le journal d'audit.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(MissionOrder $order): array
    {
        $order->load(['costs', 'legs', 'employees.quantities', 'employees.totals']);

        return [
            'reference' => $order->reference,
            'type' => $order->type?->value,
            'mission_object_id' => $order->mission_object_id,
            'departure_at' => $order->departure_at?->format('Y-m-d H:i'),
            'return_at' => $order->return_at?->format('Y-m-d H:i'),
            'departure_place_id' => $order->departure_place_id,
            'destination_place_id' => $order->destination_place_id,
            'declared_duration' => $order->declared_duration,
            'additional_costs' => $order->additional_costs,
            'assignment_allowance' => $order->assignment_allowance,
            'validator_id' => $order->validator_id,
            'validated_at' => $order->validated_at?->format('Y-m-d H:i'),
            'administrative_status' => $order->administrative_status->value,
            'notes' => $order->notes,
            'costs' => $order->costs->map(fn (MissionOrderCost $cost) => [
                'nature' => $cost->nature->value,
                'label' => $cost->label,
                'amount' => $cost->amount,
            ])->all(),
            'legs' => $order->legs->map(fn (TransportLeg $leg) => [
                'direction' => $leg->direction->value,
                'position' => $leg->position,
                'transport_mode_id' => $leg->transport_mode_id,
                'from_place_id' => $leg->from_place_id,
                'to_place_id' => $leg->to_place_id,
                'cost' => $leg->cost,
            ])->all(),
            'employees' => $order->employees->map(fn (MissionOrderEmployee $member) => [
                'employee_id' => $member->employee_id,
                'category_id' => $member->category_id,
                'declared_per_diem' => $member->declared_per_diem,
                'calculated_per_diem' => $member->calculated_per_diem,
                'rate_version_id' => $member->rate_version_id,
                'quantities' => $member->quantities->mapWithKeys(fn (ConsumptionQuantity $quantity) => [
                    $quantity->consumption_type->value.'|'.$quantity->zone_id => $quantity->quantity,
                ])->all(),
                'totals' => $member->totals->mapWithKeys(fn (ConsumptionTotal $total) => [$total->consumption_type->value => $total->total])->all(),
            ])->all(),
        ];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $costs
     */
    private function syncCosts(MissionOrder $order, array $costs): void
    {
        MissionOrderCost::query()->where('mission_order_id', $order->id)->delete();

        foreach (array_values($costs) as $position => $cost) {
            $row = new MissionOrderCost([
                'position' => $position,
                'nature' => $cost['nature'],
                'label' => $cost['label'] ?? null,
                'amount' => $cost['amount'] ?? null,
            ]);
            $row->mission_order_id = $order->id;
            $row->save();
        }
    }

    /**
     * L'ordre de la liste fait l'ordre des étapes, dans chaque sens.
     *
     * @param  array<array-key, array<string, mixed>>  $legs
     */
    private function syncLegs(MissionOrder $order, array $legs): void
    {
        TransportLeg::query()->where('mission_order_id', $order->id)->delete();

        $positions = [];
        foreach ($legs as $leg) {
            $direction = $leg['direction'];
            $positions[$direction] = ($positions[$direction] ?? 0) + 1;

            $row = new TransportLeg([
                'direction' => $direction,
                'position' => $positions[$direction],
                'transport_mode_id' => $leg['transport_mode_id'] ?? null,
                'from_place_id' => $leg['from_place_id'] ?? null,
                'to_place_id' => $leg['to_place_id'] ?? null,
                'cost' => $leg['cost'] ?? null,
            ]);
            $row->mission_order_id = $order->id;
            $row->save();
        }
    }

    /**
     * Les missionnaires sont reconnus par leur employé ; ceux qui ne sont
     * plus dans la liste sont retirés de l'OM.
     *
     * @param  array<array-key, array<string, mixed>>  $employees
     */
    private function syncEmployees(MissionOrder $order, array $employees): void
    {
        $existing = $order->employees()->get()->keyBy('employee_id');
        $kept = [];

        foreach (array_values($employees) as $position => $values) {
            $employeeId = (int) $values['employee_id'];
            $member = $existing->get($employeeId) ?? new MissionOrderEmployee;
            $member->fill([
                'employee_id' => $employeeId,
                'position' => $position,
                'category_id' => $values['category_id'] ?? null,
                'declared_per_diem' => $values['declared_per_diem'] ?? null,
            ]);
            $member->mission_order_id = $order->id;
            $member->save();
            $kept[] = $member->id;

            if (array_key_exists('quantities', $values)) {
                $member->quantities()->delete();
                foreach ($values['quantities'] as $quantity) {
                    $member->quantities()->create([
                        'consumption_type' => $quantity['consumption_type'],
                        'zone_id' => $quantity['zone_id'],
                        'quantity' => $quantity['quantity'],
                    ]);
                }
            }
            if (array_key_exists('totals', $values)) {
                $member->totals()->delete();
                foreach ($values['totals'] as $total) {
                    $member->totals()->create(['consumption_type' => $total['consumption_type'], 'total' => $total['total']]);
                }
            }
        }

        MissionOrderEmployee::query()->where('mission_order_id', $order->id)->whereNotIn('id', $kept)->delete();
    }

    /**
     * Le barème est celui en vigueur à la date de départ. Une version déjà
     * retenue est gardée tant que la date de départ ne change pas : un
     * nouveau barème ne modifie pas en silence un calcul enregistré.
     */
    private function calculatePerDiem(MissionOrder $order, ?string $previousDeparture, ?int $previousVersionId): void
    {
        $members = $order->employees()->with(['category', 'quantities'])->get();
        if ($members->isEmpty()) {
            return;
        }

        $departure = $order->departure_at;
        $version = match (true) {
            $departure === null => null,
            $previousVersionId !== null && $previousDeparture === $departure->toDateString() => RateVersion::query()->find($previousVersionId),
            default => RateVersion::applicableOn($departure),
        };
        $reason = $departure === null ? 'Date de départ manquante : le barème applicable ne peut pas être déterminé.' : null;

        foreach ($members as $member) {
            $consumptions = $member->quantities->map(fn (ConsumptionQuantity $quantity) => new Consumption(
                $quantity->consumption_type,
                $quantity->zone_id,
                $quantity->quantity,
            ));
            $result = $this->calculator->calculate($member->category, $consumptions, $version, $reason);

            $member->forceFill([
                'calculated_per_diem' => $result->total,
                'calculation_complete' => $result->complete,
                'calculation' => $result->toArray(),
                'rate_version_id' => $version?->id,
                'calculated_at' => now(),
            ])->save();
        }

        $version?->lock();
    }

    /**
     * La destination d'un OM de type Affectation devient l'affectation de
     * chaque missionnaire, à la date de départ. Rien n'est défait si l'OM
     * change ensuite de type ou perd un missionnaire : l'historique garde
     * la trace et le contrôle signale l'écart.
     */
    private function applyAssignment(MissionOrder $order, ?User $actor): void
    {
        if ($order->type !== MissionOrderType::Assignment || $order->destination_place_id === null) {
            return;
        }

        foreach ($order->employees()->with('employee')->get() as $member) {
            $employee = $member->employee;
            $applied = AssignmentHistory::query()
                ->where('mission_order_id', $order->id)
                ->where('employee_id', $employee->id)
                ->latest('id')
                ->value('new_place_id');

            if ($applied === $order->destination_place_id || $employee->current_place_id === $order->destination_place_id) {
                continue;
            }

            ($this->saveEmployee)(
                $employee,
                ['current_place_id' => $order->destination_place_id],
                AssignmentSource::MissionOrder,
                $actor,
                $order->departure_at?->copy()->startOfDay(),
                $order->id,
            );
        }
    }
}
