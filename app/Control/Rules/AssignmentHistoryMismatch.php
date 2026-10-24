<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class AssignmentHistoryMismatch extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'AFF-04', AnomalyCategory::Assignments, AnomalyNature::Inconsistency,
            'Affectation actuelle différente de l\'historique',
            'L\'affectation actuelle d\'un missionnaire ne correspond pas à la dernière ligne de son historique d\'affectation.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $employee = $member->employee;
            $latest = $employee->latestAssignment;
            $current = $employee->currentPlace->name ?? 'aucune';

            if ($latest === null) {
                if ($employee->current_place_id !== null) {
                    yield new Finding('L\'affectation actuelle '.Describe::of(Describe::employee($employee))." ({$current}) ne figure pas dans son historique d'affectation.", $member);
                }
            } elseif ($latest->new_place_id !== $employee->current_place_id) {
                yield new Finding(
                    'L\'affectation actuelle '.Describe::of(Describe::employee($employee))." ({$current}) diffère de la dernière ligne de son historique ("
                    .($latest->newPlace->name ?? 'aucune').($latest->effective_date !== null ? ', au '.$latest->effective_date->format('d/m/Y') : '').').',
                    $member,
                );
            }
        }
    }
}
