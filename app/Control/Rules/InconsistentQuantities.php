<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Models\ConsumptionQuantity;

final class InconsistentQuantities extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'PD-05', AnomalyCategory::PerDiem, AnomalyNature::Inconsistency,
            'Répartition des quantités incohérente',
            'Une quantité est négative, ou le total déclaré d\'un type de consommation diffère de la somme des quantités réparties entre les zones.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $name = Describe::employee($member->employee);

            $negative = $member->quantities
                ->filter(fn (ConsumptionQuantity $quantity) => $quantity->quantity < 0)
                ->map(fn (ConsumptionQuantity $quantity) => "{$quantity->consumption_type->label()} ({$context->zoneLabel($quantity->zone_id)}) : {$quantity->quantity}")
                ->values()
                ->all();
            if ($negative !== []) {
                yield new Finding("{$name} : quantité négative pour ".Describe::list($negative).'.', $member, 'negative');
            }

            $gaps = [];
            foreach ($member->totals as $total) {
                $sum = (int) $member->quantities
                    ->filter(fn (ConsumptionQuantity $quantity) => $quantity->consumption_type === $total->consumption_type)
                    ->sum('quantity');
                if ($sum !== $total->total) {
                    $gaps[] = "{$total->consumption_type->label()}, total déclaré {$total->total}, somme des zones {$sum}";
                }
            }
            if ($gaps !== []) {
                yield new Finding("{$name} : ".implode(' ; ', $gaps).'.', $member, 'totals');
            }
        }
    }
}
