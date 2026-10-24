<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Models\MissionOrderCost;
use App\Models\TransportLeg;

/**
 * Le montant global reste associé à l'OM et ne se multiplie pas par le
 * nombre de missionnaires (§ 12.1).
 */
final class CostBreakdownMismatch extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'FRA-02', AnomalyCategory::Costs, AnomalyNature::Inconsistency,
            'Frais différents du montant global',
            'La somme de la ventilation diffère du montant global des frais supplémentaires, ou les frais portés sur les étapes le dépassent.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        $global = $order->additional_costs;
        if ($global === null) {
            return;
        }

        if ($order->costs->isNotEmpty()) {
            $sum = (int) $order->costs->sum(fn (MissionOrderCost $cost) => $cost->amount ?? 0);
            if ($sum !== $global) {
                yield new Finding('Somme de la ventilation : '.Describe::amount($sum).' ; montant global des frais supplémentaires : '.Describe::amount($global).'.', null, 'breakdown');
            }
        }

        $legCosts = (int) $order->legs->sum(fn (TransportLeg $leg) => $leg->cost ?? 0);
        if ($legCosts > $global) {
            yield new Finding('Les frais portés sur les étapes ('.Describe::amount($legCosts).') dépassent le montant global des frais supplémentaires ('.Describe::amount($global).').', null, 'legs');
        }
    }
}
