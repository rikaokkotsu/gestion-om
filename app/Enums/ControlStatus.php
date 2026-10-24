<?php

namespace App\Enums;

/**
 * Avancement du contrôle de l'OM, propre à l'application (alimenté par le
 * moteur de contrôle).
 */
enum ControlStatus: string
{
    case NotControlled = 'NOT_CONTROLLED';
    case InProgress = 'IN_PROGRESS';
    case NoAnomaly = 'NO_ANOMALY';
    case WithAnomalies = 'WITH_ANOMALIES';

    public function label(): string
    {
        return match ($this) {
            self::NotControlled => 'Non contrôlé',
            self::InProgress => 'Contrôle en cours',
            self::NoAnomaly => 'Contrôlé sans anomalie',
            self::WithAnomalies => 'Contrôlé avec anomalies',
        };
    }
}
