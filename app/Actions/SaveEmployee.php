<?php

namespace App\Actions;

use App\Enums\AssignmentSource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Crée ou met à jour une fiche employé. Tout changement d'affectation
 * (y compris la première) ajoute une ligne à l'historique, pour que
 * l'affectation actuelle corresponde toujours à la dernière ligne
 * enregistrée. Sans date fournie, un changement prend effet aujourd'hui ;
 * une affectation initiale, ou issue d'un OM sans date de départ, garde
 * une date inconnue.
 */
class SaveEmployee
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        ?Employee $employee,
        array $data,
        AssignmentSource $source,
        ?User $recordedBy = null,
        ?Carbon $effectiveDate = null,
        ?int $missionOrderId = null,
    ): Employee {
        return DB::transaction(function () use ($employee, $data, $source, $recordedBy, $effectiveDate, $missionOrderId) {
            $employee ??= new Employee(['is_active' => true, 'is_validator' => false]);
            $previousPlaceId = $employee->exists ? $employee->current_place_id : null;

            $employee->fill($data)->save();

            if ($employee->current_place_id !== $previousPlaceId) {
                $employee->assignmentHistories()->create([
                    'previous_place_id' => $previousPlaceId,
                    'new_place_id' => $employee->current_place_id,
                    'effective_date' => ($effectiveDate ?? ($previousPlaceId === null || $source === AssignmentSource::MissionOrder ? null : now()))?->toDateString(),
                    'source' => $source,
                    'mission_order_id' => $missionOrderId,
                    'recorded_by_id' => $recordedBy?->id,
                ]);
            }

            return $employee;
        });
    }
}
