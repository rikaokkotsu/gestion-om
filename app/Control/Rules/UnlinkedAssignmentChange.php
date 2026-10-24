<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\MissionOrderType;
use App\Models\AssignmentHistory;

/**
 * Rapproche les changements d'affectation rattachés à l'OM de ce que l'OM
 * indique aujourd'hui. L'application ne défait jamais un changement : si
 * l'OM a changé depuis, l'écart est signalé ici.
 */
final class UnlinkedAssignmentChange extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'AFF-03', AnomalyCategory::Assignments, AnomalyNature::Inconsistency,
            'Affectation et OM non concordants',
            'Un missionnaire d\'un OM d\'affectation n\'a pas de changement d\'affectation rattaché à l\'OM, ce changement ne mène pas à la destination, ou un changement reste rattaché à un OM qui n\'est plus d\'affectation ou dont la personne n\'est plus missionnaire.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        $assignment = $order->type === MissionOrderType::Assignment;
        $linked = $order->assignmentHistories->sortBy('id')->groupBy('employee_id');

        if ($assignment && $order->destinationPlace !== null) {
            $destination = $order->destinationPlace;

            foreach ($context->members() as $member) {
                $name = Describe::employee($member->employee);
                /** @var AssignmentHistory|null $latest */
                $latest = $linked->get($member->employee_id)?->last();

                if ($latest === null) {
                    yield new Finding("Aucun changement d'affectation n'est rattaché à cet OM pour {$name}, alors que l'OM l'affecte à {$destination->name}.", $member, 'missing');
                } elseif ($latest->new_place_id !== $destination->id) {
                    yield new Finding("Le changement d'affectation rattaché à cet OM place {$name} à ".($latest->newPlace->name ?? 'un lieu inconnu').", alors que la destination de l'OM est {$destination->name}.", $member, 'destination');
                }
            }
        }

        foreach ($linked as $employeeId => $rows) {
            $member = $context->member((int) $employeeId);
            $employee = $rows->firstOrFail()->employee;

            if (! $assignment) {
                yield new Finding('Un changement d\'affectation '.Describe::of(Describe::employee($employee)).' est rattaché à cet OM, qui n\'est plus de type Affectation.', $member, 'type', $employee);
            } elseif ($member === null) {
                yield new Finding('Un changement d\'affectation '.Describe::of(Describe::employee($employee)).' est rattaché à cet OM, alors que cette personne n\'en est plus missionnaire.', null, 'removed', $employee);
            }
        }
    }
}
