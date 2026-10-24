<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class NoMissionary extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'OM-03', AnomalyCategory::MissionOrders, AnomalyNature::Missing,
            'Aucun missionnaire',
            'L\'OM ne désigne aucun missionnaire.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        if ($context->members()->isEmpty()) {
            yield new Finding('L\'OM ne désigne aucun missionnaire.');
        }
    }
}
