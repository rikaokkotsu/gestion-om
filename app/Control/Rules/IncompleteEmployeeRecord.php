<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class IncompleteEmployeeRecord extends Rule
{
    private const FIELDS = [
        'first_name' => 'prénom',
        'position' => 'poste',
        'service_id' => 'service',
        'current_place_id' => 'affectation actuelle',
        'account_number' => 'numéro de compte',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'EMP-03', AnomalyCategory::Employees, AnomalyNature::Missing,
            'Informations administratives manquantes',
            'La fiche d\'un missionnaire n\'a pas de prénom, de poste, de service, d\'affectation actuelle ou de numéro de compte.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $missing = [];
            foreach (self::FIELDS as $field => $label) {
                $value = $member->employee->getAttribute($field);
                if ($value === null || $value === '') {
                    $missing[] = $label;
                }
            }

            if ($missing !== []) {
                yield new Finding('Fiche '.Describe::of(Describe::employee($member->employee)).' incomplète : '.Describe::list($missing).' manquant'.(count($missing) > 1 ? 's' : '').'.', $member);
            }
        }
    }
}
