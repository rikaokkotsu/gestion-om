<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class UnauthorizedValidator extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'VAL-02', AnomalyCategory::Validations, AnomalyNature::Error,
            'Validateur non habilité',
            'Le validateur désigné n\'est pas habilité à valider les OM selon sa fiche employé.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $validator = $context->order->validator;

        if ($validator !== null && ! $validator->is_validator) {
            yield new Finding(Describe::employee($validator).' n\'est pas habilité à valider les OM.', $context->member($validator->id), '', $validator);
        }
    }
}
