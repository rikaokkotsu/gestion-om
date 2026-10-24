<?php

namespace App\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Durée d'une mission selon la convention configurée (config/om.php).
 * Une date de retour antérieure au départ donne une durée nulle ou
 * négative, que le contrôle signale.
 */
class MissionDuration
{
    public const INCLUSIVE_DAYS = 'inclusive_days';

    public const NIGHTS = 'nights';

    public const STARTED_DAYS = 'started_days';

    public static function convention(): string
    {
        $convention = config('om.duration_convention');

        return is_string($convention) ? $convention : self::INCLUSIVE_DAYS;
    }

    public static function label(?string $convention = null): string
    {
        return match ($convention ?? self::convention()) {
            self::INCLUSIVE_DAYS => 'jours calendaires, départ et retour compris',
            self::NIGHTS => 'nombre de nuits',
            self::STARTED_DAYS => 'tranches de 24 heures entamées',
            default => 'convention inconnue',
        };
    }

    public static function between(CarbonInterface $departure, CarbonInterface $return, ?string $convention = null): int
    {
        $days = (int) $departure->copy()->startOfDay()->diffInDays($return->copy()->startOfDay(), false);

        return match ($convention ?? self::convention()) {
            self::INCLUSIVE_DAYS => $days + 1,
            self::NIGHTS => $days,
            self::STARTED_DAYS => (int) ceil($departure->diffInMinutes($return, false) / 1440),
            default => throw new InvalidArgumentException('Convention de durée inconnue.'),
        };
    }
}
