<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\PerDiem\PerDiemLine;

final class NotApplicableConsumption extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'PD-03', AnomalyCategory::PerDiem, AnomalyNature::Inconsistency,
            'Consommation sans objet pour la catégorie',
            'Une quantité est attribuée pour un type de consommation marqué sans objet pour la catégorie dans le barème (par exemple des repas au détail pour un DG, qui a un forfait repas).',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            /** @var list<array<string, mixed>> $lines */
            $lines = $member->calculation['lines'] ?? [];
            $items = array_values(array_map(
                fn (array $line) => $line['consumption_label'].' ('.$context->zoneLabel((int) $line['zone_id']).')',
                array_filter($lines, fn (array $line) => $line['status'] === PerDiemLine::NOT_APPLICABLE),
            ));

            if ($items !== []) {
                yield new Finding(
                    Describe::employee($member->employee).' : '.Describe::list($items).' sans objet pour la catégorie '.($member->category->label ?? '').'.',
                    $member,
                );
            }
        }
    }
}
