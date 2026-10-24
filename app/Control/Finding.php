<?php

namespace App\Control;

use App\Models\Employee;
use App\Models\MissionOrderEmployee;

/**
 * Situation relevée par une règle. `target` distingue les situations
 * qu'une même règle relève plusieurs fois sur un OM (une étape, un type
 * de repas…) ; avec la règle et l'employé, il identifie l'anomalie d'un
 * contrôle à l'autre.
 */
final readonly class Finding
{
    public function __construct(
        public string $description,
        public ?MissionOrderEmployee $member = null,
        public string $target = '',
        public ?Employee $employee = null,
    ) {}

    public function employeeId(): ?int
    {
        return $this->employee->id ?? $this->member?->employee_id;
    }
}
