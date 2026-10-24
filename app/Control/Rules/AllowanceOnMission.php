<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\MissionOrderType;

final class AllowanceOnMission extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'AFF-01', AnomalyCategory::Assignments, AnomalyNature::Error,
            'Indemnité d\'affectation sur un OM de mission',
            'Une indemnité d\'affectation figure sur un OM de type Mission ; elle est réservée aux OM d\'affectation (§ 12.2).',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        $allowance = $order->assignment_allowance;

        if ($order->type === MissionOrderType::Mission && $allowance !== null && $allowance !== 0) {
            yield new Finding('Indemnité d\'affectation de '.Describe::amount($allowance).' sur un OM de type Mission.');
        }
    }
}
