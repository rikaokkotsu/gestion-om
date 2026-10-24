<?php

namespace App\Control\Rules;

use App\Control\ControlContext;
use App\Control\Finding;
use App\Control\Rule;
use App\Control\RuleDefinition;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\MissionOrderType;

final class TypeObjectMismatch extends Rule
{
    /**
     * Libellé de l'objet de mission propre aux OM d'affectation (§ 5.3).
     */
    public const ASSIGNMENT_OBJECT = 'affectation';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'OM-05', AnomalyCategory::MissionOrders, AnomalyNature::Inconsistency,
            'Type et objet de mission incohérents',
            'Un OM d\'affectation a un autre objet que « Affectation », ou un OM de mission a l\'objet « Affectation ».',
        );
    }

    public function check(ControlContext $context): iterable
    {
        $order = $context->order;
        $object = $order->missionObject;
        if ($order->type === null || $object === null) {
            return;
        }

        $assignmentObject = mb_strtolower(trim($object->label)) === self::ASSIGNMENT_OBJECT;

        if ($order->type === MissionOrderType::Assignment && ! $assignmentObject) {
            yield new Finding("L'OM est de type Affectation mais son objet est « {$object->label} ».");
        } elseif ($order->type === MissionOrderType::Mission && $assignmentObject) {
            yield new Finding('L\'OM est de type Mission mais son objet est « Affectation ».');
        }
    }
}
