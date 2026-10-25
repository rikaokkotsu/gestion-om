<?php

namespace App\Reports;

use App\Models\Employee;
use DateTimeInterface;

/**
 * Mise en forme des valeurs dans les rapports PDF ; une valeur absente
 * s'affiche « — », jamais 0.
 */
final class Format
{
    public static function amount(?int $amount): string
    {
        return $amount === null ? '—' : number_format($amount, 0, ',', ' ').' Ar';
    }

    public static function number(?int $value): string
    {
        return $value === null ? '—' : number_format($value, 0, ',', ' ');
    }

    public static function date(?DateTimeInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '—';
    }

    public static function datetime(?DateTimeInterface $date): string
    {
        return $date === null ? '—' : $date->format('d/m/Y à H:i');
    }

    public static function employee(?Employee $employee): string
    {
        return $employee?->auditLabel() ?? '—';
    }

    public static function days(?int $days): string
    {
        return $days === null ? '—' : $days.' '.(abs($days) > 1 ? 'jours' : 'jour');
    }

    /**
     * « 3 OM », « 1 anomalie », « 2 anomalies ».
     */
    public static function count(int $count, string $singular, ?string $plural = null): string
    {
        return self::number($count).' '.($count > 1 ? $plural ?? $singular.'s' : $singular);
    }
}
