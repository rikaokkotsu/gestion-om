<?php

namespace App\Enums;

/**
 * Types de consommation couverts par le per diem (§ 10.1, 11.1). Le forfait
 * repas remplace petit-déjeuner, déjeuner et dîner pour les catégories DG,
 * DGA et Directeur (décision du 24 octobre 2026).
 */
enum ConsumptionType: string
{
    case Breakfast = 'PETIT_DEJ';
    case Lunch = 'DEJEUNER';
    case Dinner = 'DINER';
    case MealAllowance = 'FORFAIT_REPAS';
    case Night = 'NUITEE';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Petit-déjeuner',
            self::Lunch => 'Déjeuner',
            self::Dinner => 'Dîner',
            self::MealAllowance => 'Forfait repas',
            self::Night => 'Nuitée',
        };
    }
}
