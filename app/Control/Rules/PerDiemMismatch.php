<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

/**
 * Le calcul sert de référence au contrôle ; le montant déclaré n'est jamais modifié.
 */
final class PerDiemMismatch extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'PD-01', AnomalyCategory::PerDiem, AnomalyNature::Error,
            'Per diem déclaré différent du calculé',
            'Le per diem déclaré d\'un missionnaire diffère du montant calculé avec le barème applicable. Seul un calcul complet est comparé.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $declared = $member->declared_per_diem;
            $calculated = $member->calculated_per_diem;

            if ($member->calculation_complete === true && $declared !== null && $calculated !== null && $declared !== $calculated) {
                $gap = Describe::amount(abs($calculated - $declared));
                yield new Finding(
                    Describe::employee($member->employee).' : per diem déclaré '.Describe::amount($declared).', calculé '.Describe::amount($calculated)
                    .' (déclaré '.($declared < $calculated ? 'inférieur' : 'supérieur')." de {$gap}).",
                    $member,
                );
            }
        }
    }
}
