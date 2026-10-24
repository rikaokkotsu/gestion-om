<?php

namespace App\Enums;

/**
 * Origine d'un changement d'affectation.
 */
enum AssignmentSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case MissionOrder = 'om';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Saisie manuelle',
            self::Import => 'Import',
            self::MissionOrder => 'OM d\'affectation',
        };
    }
}
