<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\CostNature;
use App\Models\MissionOrderCost;
use App\Models\TransportLeg;

/**
 * Un moyen payant ne prouve pas à lui seul qu'un montant est faux (§ 9.4) :
 * la règle relève seulement ce qui mérite une vérification.
 */
final class TransportCostToVerify extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'TRA-04', AnomalyCategory::Transport, AnomalyNature::Check,
            'Frais de transport à vérifier',
            'Un moyen payant est utilisé sans aucun frais de transport renseigné, ou les frais des étapes diffèrent des frais de déplacement ventilés.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $legs = $context->order->legs;
        $legCosts = (int) $legs->sum(fn (TransportLeg $leg) => max(0, $leg->cost ?? 0));
        $transportCosts = $context->order->costs->filter(fn (MissionOrderCost $cost) => $cost->nature === CostNature::Transport);
        $breakdown = (int) $transportCosts->sum(fn (MissionOrderCost $cost) => max(0, $cost->amount ?? 0));
        $paying = $legs
            ->filter(fn (TransportLeg $leg) => $leg->transportMode?->generates_costs === true)
            ->map(fn (TransportLeg $leg) => (string) $leg->transportMode?->label)
            ->unique()
            ->values()
            ->all();

        if ($paying !== [] && $legCosts === 0 && $breakdown === 0) {
            yield new Finding(
                'Moyen payant utilisé ('.Describe::list($paying).') sans aucun frais de transport renseigné, ni sur les étapes ni dans la ventilation des frais.',
                null,
                'missing',
            );
        }

        if ($legCosts > 0 && $transportCosts->isNotEmpty() && $legCosts !== $breakdown) {
            yield new Finding('Frais portés sur les étapes : '.Describe::amount($legCosts).' ; frais de déplacement ventilés : '.Describe::amount($breakdown).'.', null, 'mismatch');
        }
    }
}
