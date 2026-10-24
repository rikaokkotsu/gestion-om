<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Describe;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Models\MissionOrder;

final class DuplicateReference extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'OM-01', AnomalyCategory::MissionOrders, AnomalyNature::Error,
            'Référence d\'OM en double',
            'Un autre OM enregistré porte la même référence (sans tenir compte de la casse ni des espaces autour).',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $reference = trim((string) $context->order->reference);
        if ($reference === '') {
            return;
        }

        $others = MissionOrder::query()
            ->whereKeyNot($context->order->id)
            ->whereRaw('lower(trim(reference)) = ?', [mb_strtolower($reference)])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => 'n° '.$id)
            ->all();

        if ($others !== []) {
            $count = count($others);
            yield new Finding("La référence {$reference} est aussi celle de {$count} autre".($count > 1 ? 's' : '').' OM ('.Describe::list($others).').');
        }
    }
}
