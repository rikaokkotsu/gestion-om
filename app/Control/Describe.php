<?php

namespace App\Control;

use App\Models\Employee;
use App\Models\TransportLeg;

/**
 * Mise en forme des valeurs citées dans les descriptions d'anomalies.
 */
final class Describe
{
    public static function amount(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' Ar';
    }

    public static function days(int $days): string
    {
        return $days.' '.(abs($days) > 1 ? 'jours' : 'jour');
    }

    /**
     * « de Rakoto », « d'Andry » : la préposition élidée devant une voyelle.
     */
    public static function of(string $word): string
    {
        return preg_match('/^[aeiouyàâäéèêëîïôöùûü]/iu', $word) === 1 ? "d'{$word}" : "de {$word}";
    }

    public static function employee(Employee $employee): string
    {
        return $employee->auditLabel();
    }

    public static function leg(TransportLeg $leg): string
    {
        return 'Étape '.mb_strtolower($leg->direction->label()).' n° '.$leg->position;
    }

    /**
     * « a », « a et b », « a, b et c ».
     *
     * @param  list<string>  $items
     */
    public static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' et '.$last;
    }
}
