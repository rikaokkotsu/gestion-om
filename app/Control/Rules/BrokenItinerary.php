<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\TravelDirection;
use App\Models\Place;

/**
 * L'aller va du lieu de départ à la destination, le retour en sens
 * inverse ; seuls les lieux renseignés sont comparés.
 */
final class BrokenItinerary extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'TRA-03', AnomalyCategory::Transport, AnomalyNature::Inconsistency,
            'Ordre ou lieux des étapes incohérents',
            'Les étapes d\'un trajet ne s\'enchaînent pas, une étape arrive où elle part, ou le trajet ne part pas du lieu attendu ou n\'arrive pas au lieu attendu.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;

        foreach (TravelDirection::cases() as $direction) {
            $legs = $context->legs($direction);
            if ($legs === []) {
                continue;
            }

            $outbound = $direction === TravelDirection::Outbound;
            $start = $outbound ? $order->departurePlace : $order->destinationPlace;
            $end = $outbound ? $order->destinationPlace : $order->departurePlace;
            $trip = 'Trajet '.mb_strtolower($direction->label());
            $first = $legs[0];
            $last = $legs[count($legs) - 1];

            if ($this->differ($first->fromPlace, $start)) {
                yield new Finding(
                    "{$trip} : départ de la première étape ({$first->fromPlace?->name}) différent du "
                    .($outbound ? 'lieu de départ' : 'lieu de destination')." de l'OM ({$start?->name}).",
                    null,
                    "{$direction->value}-start",
                );
            }

            foreach ($legs as $index => $leg) {
                if ($leg->from_place_id !== null && $leg->from_place_id === $leg->to_place_id) {
                    yield new Finding(Describe::leg($leg)." : départ et arrivée au même lieu ({$leg->fromPlace?->name}).", null, "{$direction->value}-{$leg->position}-same");
                }

                $previous = $legs[$index - 1] ?? null;
                if ($previous !== null && $this->differ($previous->toPlace, $leg->fromPlace)) {
                    yield new Finding(
                        "{$trip} : arrivée de l'étape n° {$previous->position} ({$previous->toPlace?->name}) différente du départ de l'étape n° {$leg->position} ({$leg->fromPlace?->name}).",
                        null,
                        "{$direction->value}-{$leg->position}",
                    );
                }
            }

            if ($this->differ($last->toPlace, $end)) {
                yield new Finding(
                    "{$trip} : arrivée de la dernière étape ({$last->toPlace?->name}) différente du "
                    .($outbound ? 'lieu de destination' : 'lieu de départ')." de l'OM ({$end?->name}).",
                    null,
                    "{$direction->value}-end",
                );
            }
        }
    }

    private function differ(?Place $actual, ?Place $expected): bool
    {
        return $actual !== null && $expected !== null && $actual->id !== $expected->id;
    }
}
