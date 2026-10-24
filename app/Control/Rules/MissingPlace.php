<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\MissionOrderType;

final class MissingPlace extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'OM-04', AnomalyCategory::MissionOrders, AnomalyNature::Missing,
            'Lieu manquant ou sans zone',
            'Le lieu de départ ou de destination n\'est pas renseigné, ou n\'a pas de zone dans le référentiel des lieux.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;

        if ($order->departurePlace === null) {
            yield new Finding('Le lieu de départ n\'est pas renseigné.', null, 'departure');
        } elseif ($order->departurePlace->zone_id === null) {
            yield new Finding("Le lieu de départ ({$order->departurePlace->name}) n'a pas de zone dans le référentiel.", null, 'departure_zone');
        }

        // Sur un OM d'affectation, une destination absente est relevée par AFF-02.
        if ($order->destinationPlace === null) {
            if ($order->type !== MissionOrderType::Assignment) {
                yield new Finding('Le lieu de destination n\'est pas renseigné.', null, 'destination');
            }
        } elseif ($order->destinationPlace->zone_id === null) {
            yield new Finding("Le lieu de destination ({$order->destinationPlace->name}) n'a pas de zone dans le référentiel.", null, 'destination_zone');
        }
    }
}
