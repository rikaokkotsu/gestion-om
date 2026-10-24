<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class MissingDates extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'DAT-03', AnomalyCategory::Chronology, AnomalyNature::Missing,
            'Date ou durée manquante ou invalide',
            'La date de départ, la date de retour ou la durée déclarée manque, ou la durée déclarée est nulle ou négative.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;

        if ($order->departure_at === null) {
            yield new Finding('La date de départ n\'est pas renseignée.', null, 'departure');
        }
        if ($order->return_at === null) {
            yield new Finding('La date de retour n\'est pas renseignée.', null, 'return');
        }
        if ($order->declared_duration === null) {
            yield new Finding('La durée de la mission n\'est pas renseignée.', null, 'duration');
        } elseif ($order->declared_duration <= 0) {
            yield new Finding("La durée déclarée ({$order->declared_duration}) est nulle ou négative.", null, 'duration');
        }
    }
}
