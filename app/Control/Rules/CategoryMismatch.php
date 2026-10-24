<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class CategoryMismatch extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'PD-04', AnomalyCategory::PerDiem, AnomalyNature::Inconsistency,
            'Catégorie de l\'OM différente de la fiche',
            'La catégorie retenue sur l\'OM, qui sert au calcul, diffère de la catégorie de la fiche employé.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $onOrder = $member->category;
            $onRecord = $member->employee->category;

            if ($onOrder !== null && $onRecord !== null && $onOrder->id !== $onRecord->id) {
                yield new Finding(Describe::employee($member->employee)." : catégorie {$onOrder->label} sur l'OM, {$onRecord->label} sur la fiche employé.", $member);
            }
        }
    }
}
