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

final class MissingCostAmount extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'FRA-01', AnomalyCategory::Costs, AnomalyNature::Missing,
            'Montant de frais manquant',
            'Le montant global des frais supplémentaires manque alors que des frais sont ventilés ou portés sur les étapes, ou une ligne de ventilation n\'a pas de montant.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        $legCosts = $order->legs->contains(fn (TransportLeg $leg) => ($leg->cost ?? 0) !== 0);

        if ($order->additional_costs === null && ($order->costs->isNotEmpty() || $legCosts)) {
            yield new Finding(
                'Le montant global des frais supplémentaires n\'est pas renseigné, alors que des frais sont '
                .($order->costs->isNotEmpty() ? 'ventilés.' : 'portés sur les étapes de transport.'),
                null,
                'global',
            );
        }

        $withoutAmount = $order->costs
            ->filter(fn (MissionOrderCost $cost) => $cost->amount === null)
            ->map(fn (MissionOrderCost $cost) => $cost->nature->label().($cost->label !== null ? " ({$cost->label})" : ''))
            ->values()
            ->all();
        if ($withoutAmount !== []) {
            yield new Finding('Ligne'.(count($withoutAmount) > 1 ? 's' : '').' de ventilation sans montant : '.Describe::list($withoutAmount).'.', null, 'lines');
        }
    }
}
