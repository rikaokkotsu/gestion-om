<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

final class MissingCategory extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'EMP-02', AnomalyCategory::Employees, AnomalyNature::Missing,
            'Catégorie manquante ou non reconnue',
            'La fiche d\'un missionnaire n\'a pas de catégorie, aucune catégorie n\'est retenue sur l\'OM, ou la catégorie retenue est désactivée.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $name = Describe::employee($member->employee);

            if ($member->employee->category_id === null) {
                yield new Finding('La fiche '.Describe::of($name)." n'a pas de catégorie.", $member, 'employee');
            }
            if ($member->category === null) {
                yield new Finding("Aucune catégorie n'est retenue pour {$name} sur l'OM.", $member, 'order');
            } elseif (! $member->category->is_active) {
                yield new Finding("La catégorie retenue pour {$name} sur l'OM ({$member->category->label}) est désactivée dans le référentiel.", $member, 'inactive');
            }
        }
    }
}
