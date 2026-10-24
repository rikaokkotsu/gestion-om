<?php

namespace App\Enums;

/**
 * Entrée de l'historique d'une anomalie.
 */
enum AnomalyEventType: string
{
    case Created = 'CREATED';
    case StateChanged = 'STATE_CHANGED';
    case Commented = 'COMMENTED';
    case Updated = 'UPDATED';
    case Redetected = 'REDETECTED';
    case NoLongerDetected = 'NO_LONGER_DETECTED';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Création',
            self::StateChanged => 'Changement d\'état',
            self::Commented => 'Commentaire',
            self::Updated => 'Modification',
            self::Redetected => 'Détectée à nouveau',
            self::NoLongerDetected => 'N\'est plus détectée',
        };
    }
}
