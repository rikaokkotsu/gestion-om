<?php

namespace App\Enums;

/**
 * Catégories d'anomalies du § 13.2.
 */
enum AnomalyCategory: string
{
    case Employees = 'A';
    case Validations = 'B';
    case MissionOrders = 'C';
    case Chronology = 'D';
    case Transport = 'E';
    case Assignments = 'F';
    case PerDiem = 'G';
    case Costs = 'H';

    public function label(): string
    {
        return match ($this) {
            self::Employees => 'Employés',
            self::Validations => 'Validations',
            self::MissionOrders => 'OM',
            self::Chronology => 'Chronologie',
            self::Transport => 'Transport',
            self::Assignments => 'Affectations',
            self::PerDiem => 'Per diem',
            self::Costs => 'Frais',
        };
    }
}
