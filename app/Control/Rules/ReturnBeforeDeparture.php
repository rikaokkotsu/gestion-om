<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class ReturnBeforeDeparture extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'DAT-01', AnomalyCategory::Chronology, AnomalyNature::Error,
            'Retour antérieur au départ',
            'La date et l\'heure de retour précèdent celles du départ.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;

        if ($order->departure_at !== null && $order->return_at !== null && $order->return_at->lessThan($order->departure_at)) {
            yield new Finding('Retour le '.$order->return_at->format('d/m/Y à H:i').', avant le départ du '.$order->departure_at->format('d/m/Y à H:i').'.');
        }
    }
}
