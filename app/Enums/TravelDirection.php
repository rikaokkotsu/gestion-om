<?php

namespace App\Enums;

/**
 * Sens d'une étape de transport (§ 9.2).
 */
enum TravelDirection: string
{
    case Outbound = 'OUTBOUND';
    case Return = 'RETURN';

    public function label(): string
    {
        return match ($this) {
            self::Outbound => 'Aller',
            self::Return => 'Retour',
        };
    }
}
