<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class InactiveEmployee extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'EMP-01', AnomalyCategory::Employees, AnomalyNature::Error,
            'Employé désactivé',
            'Un missionnaire ou le validateur a une fiche désactivée dans le référentiel.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            if (! $member->employee->is_active) {
                yield new Finding('La fiche '.Describe::of(Describe::employee($member->employee)).' est désactivée.', $member);
            }
        }

        // Un validateur également missionnaire est déjà relevé ci-dessus.
        $validator = $context->order->validator;
        if ($validator !== null && ! $validator->is_active && $context->member($validator->id) === null) {
            yield new Finding('La fiche du validateur '.Describe::employee($validator).' est désactivée.', null, 'validator', $validator);
        }
    }
}
