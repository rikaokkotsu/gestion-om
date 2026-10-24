<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Support\MissionDuration;

final class DurationMismatch extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'DAT-02', AnomalyCategory::Chronology, AnomalyNature::Inconsistency,
            'Durée incohérente avec les dates',
            'La durée déclarée diffère de la durée calculée à partir des dates, selon la convention retenue.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        [$departure, $return] = [$order->departure_at, $order->return_at];

        // Un retour antérieur au départ est relevé par DAT-01, une date ou une durée absente par DAT-03.
        if ($departure === null || $return === null || $order->declared_duration === null || $return->lessThan($departure)) {
            return;
        }

        $calculated = MissionDuration::between($departure, $return);
        if ($order->declared_duration !== $calculated) {
            yield new Finding('Durée déclarée : '.Describe::days($order->declared_duration).' ; durée selon les dates : '.Describe::days($calculated).' ('.MissionDuration::label().').');
        }
    }
}
