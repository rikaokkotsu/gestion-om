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

final class CostWithFreeTransport extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'TRA-01', AnomalyCategory::Transport, AnomalyNature::Error,
            'Frais de transport avec un moyen sans frais',
            'Une étape porte des frais alors que son moyen n\'en engendre pas (§ 9.3), ou des frais de déplacement sont ventilés sans aucune étape par moyen payant.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $legs = $context->order->legs;

        foreach ($legs as $leg) {
            $mode = $leg->transportMode;
            if ($mode !== null && ! $mode->generates_costs && ($leg->cost ?? 0) > 0) {
                yield new Finding(
                    Describe::leg($leg)." ({$mode->label}) : ".Describe::amount((int) $leg->cost).' de frais, alors que ce moyen n\'engendre pas de frais de transport.',
                    null,
                    "{$leg->direction->value}-{$leg->position}",
                );
            }
        }

        $breakdown = (int) $context->order->costs
            ->filter(fn (MissionOrderCost $cost) => $cost->nature === CostNature::Transport)
            ->sum(fn (MissionOrderCost $cost) => max(0, $cost->amount ?? 0));
        $paying = $legs->contains(fn (TransportLeg $leg) => $leg->transportMode?->generates_costs === true);

        if ($breakdown > 0 && ! $paying) {
            yield new Finding(
                'Des frais de déplacement sont ventilés ('.Describe::amount($breakdown).') alors '
                .($legs->isEmpty() ? 'qu\'aucune étape de transport n\'est renseignée.' : 'qu\'aucune étape n\'utilise un moyen payant.'),
                null,
                'breakdown',
            );
        }
    }
}
