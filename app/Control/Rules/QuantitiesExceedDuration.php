<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Models\ConsumptionQuantity;

/**
 * Les quantités sont fixées par le responsable et ne se déduisent pas de
 * la durée (§ 10.1) : un dépassement est seulement à vérifier.
 */
final class QuantitiesExceedDuration extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'DAT-04', AnomalyCategory::Chronology, AnomalyNature::Check,
            'Quantités supérieures à la durée',
            'Pour un missionnaire, le nombre de nuitées ou de repas d\'un type dépasse le nombre de jours de la mission (selon les dates, sinon la durée déclarée).',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $duration = $context->referenceDuration();
        if ($duration === null || $duration <= 0) {
            return;
        }

        foreach ($context->members() as $member) {
            $byType = $member->quantities->groupBy(fn (ConsumptionQuantity $quantity) => $quantity->consumption_type->value);

            foreach ($byType as $type => $quantities) {
                $total = (int) $quantities->sum('quantity');
                if ($total > $duration) {
                    $label = $quantities->firstOrFail()->consumption_type->label();
                    yield new Finding(
                        Describe::employee($member->employee)." : {$total} × {$label} pour une mission de ".Describe::days($duration).'.',
                        $member,
                        (string) $type,
                    );
                }
            }
        }
    }
}
