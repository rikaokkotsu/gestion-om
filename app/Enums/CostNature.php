<?php

namespace App\Enums;

/**
 * Nature d'une ligne de ventilation des frais supplémentaires (§ 12.1).
 */
enum CostNature: string
{
    case Transport = 'TRANSPORT';
    case Health = 'HEALTH';
    case Contingency = 'CONTINGENCY';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Transport => 'Frais de déplacement',
            self::Health => 'Services de santé',
            self::Contingency => 'Imprévus',
            self::Other => 'Autres dépenses',
        };
    }
}
