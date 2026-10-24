<?php

namespace App\Enums;

/**
 * État de traitement d'une anomalie. Une anomalie automatique naît
 * « proposée » : le contrôleur la confirme ou l'écarte (motif obligatoire).
 * Un signalement manuel naît « confirmé ».
 */
enum AnomalyState: string
{
    case Proposed = 'PROPOSED';
    case Confirmed = 'CONFIRMED';
    case Dismissed = 'DISMISSED';
    case Transmitted = 'TRANSMITTED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposée',
            self::Confirmed => 'Confirmée',
            self::Dismissed => 'Écartée',
            self::Transmitted => 'Transmise',
            self::Closed => 'Clôturée',
        };
    }

    /**
     * États atteignables depuis celui-ci.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Proposed => [self::Confirmed, self::Dismissed],
            self::Confirmed => [self::Transmitted, self::Closed, self::Dismissed],
            self::Transmitted => [self::Closed],
            self::Dismissed, self::Closed => [self::Confirmed],
        };
    }

    public function canBecome(self $state): bool
    {
        return in_array($state, $this->transitions(), true);
    }

    /**
     * Anomalie encore à traiter.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Proposed, self::Confirmed, self::Transmitted], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Proposed->value, self::Confirmed->value, self::Transmitted->value];
    }
}
