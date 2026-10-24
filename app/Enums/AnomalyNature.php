<?php

namespace App\Enums;

/**
 * Nature d'une anomalie (§ 13.1) ; elle donne la priorité par défaut.
 */
enum AnomalyNature: string
{
    case Error = 'ERROR';
    case Inconsistency = 'INCONSISTENCY';
    case Missing = 'MISSING';
    case Check = 'CHECK';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'Erreur',
            self::Inconsistency => 'Incohérence',
            self::Missing => 'Donnée manquante',
            self::Check => 'À vérifier',
        };
    }

    public function defaultPriority(): AnomalyPriority
    {
        return match ($this) {
            self::Error => AnomalyPriority::High,
            self::Inconsistency, self::Missing => AnomalyPriority::Medium,
            self::Check => AnomalyPriority::Low,
        };
    }
}
