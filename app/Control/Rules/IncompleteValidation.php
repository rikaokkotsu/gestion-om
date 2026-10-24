<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AdministrativeStatus;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class IncompleteValidation extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'VAL-03', AnomalyCategory::Validations, AnomalyNature::Missing,
            'Validation non traçable',
            'Un OM validé n\'indique pas son validateur ou la date de validation.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        if ($order->administrative_status !== AdministrativeStatus::Validated) {
            return;
        }

        if ($order->validator_id === null) {
            yield new Finding('L\'OM est validé mais aucun validateur n\'est indiqué.', null, 'validator');
        }
        if ($order->validated_at === null) {
            yield new Finding('L\'OM est validé mais la date de validation n\'est pas indiquée.', null, 'date');
        }
    }
}
