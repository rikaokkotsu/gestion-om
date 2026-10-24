<?php

namespace App\Control;

use App\Enums\TravelDirection;
use App\Models\MissionOrder;
use App\Models\MissionOrderEmployee;
use App\Models\TransportLeg;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Collection;

/**
 * OM à contrôler, chargé avec tout ce que lisent les règles.
 */
final class ControlContext
{
    public const RELATIONS = [
        'missionObject', 'departurePlace', 'destinationPlace', 'validator',
        'costs', 'legs.transportMode', 'legs.fromPlace', 'legs.toPlace',
        'employees.employee.category', 'employees.employee.currentPlace', 'employees.employee.latestAssignment.newPlace',
        'employees.category', 'employees.quantities', 'employees.totals',
        'assignmentHistories.employee', 'assignmentHistories.newPlace',
    ];

    /**
     * @param  Collection<int, Zone>  $zones  zones par identifiant
     */
    public function __construct(
        public readonly MissionOrder $order,
        private readonly Collection $zones,
    ) {
        $order->loadMissing(self::RELATIONS);
    }

    /**
     * @return Collection<int, MissionOrderEmployee>
     */
    public function members(): Collection
    {
        return $this->order->employees;
    }

    public function member(?int $employeeId): ?MissionOrderEmployee
    {
        return $employeeId === null ? null : $this->members()->firstWhere('employee_id', $employeeId);
    }

    /**
     * Étapes d'un sens, dans l'ordre du trajet.
     *
     * @return list<TransportLeg>
     */
    public function legs(TravelDirection $direction): array
    {
        return $this->order->legs->filter(fn (TransportLeg $leg) => $leg->direction === $direction)->values()->all();
    }

    /**
     * Durée de référence pour juger les quantités : selon les dates, sinon déclarée.
     */
    public function referenceDuration(): ?int
    {
        return $this->order->calculatedDuration() ?? $this->order->declared_duration;
    }

    public function zoneLabel(int $zoneId): string
    {
        return $this->zones->get($zoneId)->label ?? "zone n° {$zoneId}";
    }
}
