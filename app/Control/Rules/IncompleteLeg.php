<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class IncompleteLeg extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'TRA-02', AnomalyCategory::Transport, AnomalyNature::Missing,
            'Étape de transport incomplète',
            'Une étape n\'indique pas son moyen de transport, son lieu de départ ou son lieu d\'arrivée.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->order->legs as $leg) {
            $missing = array_keys(array_filter([
                'moyen de transport' => $leg->transport_mode_id === null,
                'lieu de départ' => $leg->from_place_id === null,
                'lieu d\'arrivée' => $leg->to_place_id === null,
            ]));

            if ($missing !== []) {
                yield new Finding(
                    Describe::leg($leg).' : '.Describe::list($missing).' non renseigné'.(count($missing) > 1 ? 's' : '').'.',
                    null,
                    "{$leg->direction->value}-{$leg->position}",
                );
            }
        }
    }
}
