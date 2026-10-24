<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\MissionOrderType;

final class UnknownNewAssignment extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'AFF-02', AnomalyCategory::Assignments, AnomalyNature::Missing,
            'Nouvelle affectation non identifiable',
            'Un OM d\'affectation n\'a pas de lieu de destination : la nouvelle affectation ne peut pas être déterminée.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;

        if ($order->type === MissionOrderType::Assignment && $order->destination_place_id === null) {
            yield new Finding('OM d\'affectation sans lieu de destination : la nouvelle affectation ne peut pas être déterminée.');
        }
    }
}
