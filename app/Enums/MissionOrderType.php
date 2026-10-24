<?php

namespace App\Enums;

/**
 * Type d'OM (§ 5.2), distinct de l'objet de mission. Un OM d'affectation
 * met à jour l'affectation de ses missionnaires.
 */
enum MissionOrderType: string
{
    case Mission = 'MISSION';
    case Assignment = 'AFFECTATION';

    public function label(): string
    {
        return match ($this) {
            self::Mission => 'Mission',
            self::Assignment => 'Affectation',
        };
    }
}
