<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class ValidatorIsMissionary extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'VAL-01', AnomalyCategory::Validations, AnomalyNature::Error,
            'Validateur bénéficiaire de l\'OM',
            'Le validateur désigné figure parmi les missionnaires : il ne peut pas valider son propre OM (§ 3.3).',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $validator = $context->order->validator;
        $member = $context->member($validator?->id);

        if ($validator !== null && $member !== null) {
            yield new Finding('Le validateur '.Describe::employee($validator).' figure parmi les missionnaires de l\'OM.', $member);
        }
    }
}
