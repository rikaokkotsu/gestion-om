<?php

namespace App\Enums;

enum AnomalySource: string
{
    case Automatic = 'AUTO';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Contrôle automatique',
            self::Manual => 'Signalement manuel',
        };
    }
}
