<?php

namespace App\Enums;

enum AnomalyPriority: string
{
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case Low = 'LOW';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Haute',
            self::Medium => 'Moyenne',
            self::Low => 'Basse',
        };
    }
}
