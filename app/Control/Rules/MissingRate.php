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

final class MissingRate extends Rule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            'PD-02', AnomalyCategory::PerDiem, AnomalyNature::Missing,
            'Tarif ou per diem manquant',
            'Un tarif nécessaire au calcul est absent du barème, aucun barème ne s\'applique à la date de départ, ou le per diem déclaré n\'est pas renseigné.',
        );
    }

    public function check(ControlContext $context): iterable
    {
        foreach ($context->members() as $member) {
            $calculation = $member->calculation ?? [];
            /** @var list<array<string, mixed>> $lines */
            $lines = $calculation['lines'] ?? [];
            if ($lines === []) {
                continue;
            }
            $name = Describe::employee($member->employee);

            // Sans date de départ (DAT-03) ou sans catégorie (EMP-02), le barème ne peut pas être consulté.
            if ($context->order->departure_at !== null && ($calculation['rate_version'] ?? null) === null) {
                yield new Finding("{$name} : aucun barème ne s'applique au ".$context->order->departure_at->format('d/m/Y').', le per diem ne peut pas être calculé.', $member, 'version');
            } elseif ($member->category !== null && ($calculation['rate_version'] ?? null) !== null) {
                $missing = array_values(array_map(
                    fn (array $line) => $line['consumption_label'].' ('.$context->zoneLabel((int) $line['zone_id']).')',
                    array_filter($lines, fn (array $line) => $line['status'] === PerDiemLine::MISSING_RATE),
                ));
                if ($missing !== []) {
                    $version = $calculation['rate_version']['label'] ?? '';
                    yield new Finding("{$name} : tarif absent du barème « {$version} » pour la catégorie {$member->category->label} : ".Describe::list($missing).'.', $member, 'rates');
                }
            }

            if ($member->declared_per_diem === null) {
                yield new Finding("{$name} : le per diem déclaré n'est pas renseigné.", $member, 'declared');
            }
        }
    }
}
