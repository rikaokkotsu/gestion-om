<?php

namespace App\Enums;

/**
 * Statut administratif de l'OM, recopié du document. Il ne dit rien du
 * contrôle, qui a son propre statut.
 */
enum AdministrativeStatus: string
{
    case Pending = 'PENDING';
    case Validated = 'VALIDATED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de validation',
            self::Validated => 'Validé',
            self::Rejected => 'Rejeté',
            self::Cancelled => 'Annulé',
        };
    }
}
