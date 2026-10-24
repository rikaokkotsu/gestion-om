<?php

namespace App\Enums;

/**
 * Déclenchement d'un contrôle : sur un OM, ou sur un lot d'OM.
 */
enum ControlTrigger: string
{
    case Request = 'REQUEST';
    case Batch = 'BATCH';

    public function label(): string
    {
        return match ($this) {
            self::Request => 'Sur un OM',
            self::Batch => 'Par lot',
        };
    }
}
