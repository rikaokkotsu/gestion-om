<?php

namespace App\Actions;

use App\Enums\AnomalyEventType;
use App\Models\Anomaly;
use App\Models\Employee;
use App\Models\MissionOrderEmployee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Modifie les détails d'une anomalie ; l'avant et l'après rejoignent son
 * historique et le journal d'audit.
 */
class UpdateAnomaly
{
    private const TRACKED = ['category', 'priority', 'description', 'employee_id'];

    /**
     * @param  array<string, mixed>  $data  champs modifiables validés
     */
    public function __invoke(Anomaly $anomaly, array $data, ?User $actor): Anomaly
    {
        return DB::transaction(function () use ($anomaly, $data, $actor) {
            if (array_key_exists('member_id', $data)) {
                $member = $data['member_id'] === null ? null : MissionOrderEmployee::query()->findOrFail($data['member_id']);
                $anomaly->mission_order_employee_id = $member?->id;
                $anomaly->employee_id = $member?->employee_id;
            }
            $anomaly->fill(Arr::only($data, ['category', 'priority', 'description']));

            $changed = array_values(array_intersect(self::TRACKED, array_keys($anomaly->getDirty())));
            if ($changed === []) {
                return $anomaly;
            }

            $old = Arr::only($anomaly->getRawOriginal(), $changed);
            $anomaly->save();
            $new = Arr::only($anomaly->getAttributes(), $changed);

            // L'historique nomme l'employé ; le journal d'audit garde les identifiants.
            $readable = fn (string $field, mixed $value) => $field === 'employee_id' && $value !== null ? Employee::query()->find($value)?->auditLabel() : $value;
            $anomaly->events()->create([
                'user_id' => $actor?->id,
                'type' => AnomalyEventType::Updated,
                'changes' => array_combine($changed, array_map(fn (string $field) => [
                    'old' => $readable($field, $old[$field] ?? null),
                    'new' => $readable($field, $new[$field] ?? null),
                ], $changed)),
            ]);
            Audit::record('anomaly.updated', $anomaly, $old, $new, $actor);

            return $anomaly;
        });
    }
}
