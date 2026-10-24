<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class MissingIdentification extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'OM-02', AnomalyCategory::MissionOrders, AnomalyNature::Missing,
            'Référence, type ou objet manquant',
            'L\'OM n\'a pas de référence, de type ou d\'objet de mission.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;

        if (trim((string) $order->reference) === '') {
            yield new Finding('La référence de l\'OM n\'est pas renseignée.', null, 'reference');
        }
        if ($order->type === null) {
            yield new Finding('Le type d\'OM (mission ou affectation) n\'est pas renseigné.', null, 'type');
        }
        if ($order->mission_object_id === null) {
            yield new Finding('L\'objet de la mission n\'est pas renseigné.', null, 'object');
        }
    }
}
