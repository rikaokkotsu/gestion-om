<?php

namespace App\PerDiem;

use App\Models\Category;
use App\Models\Rate;
use App\Models\RateVersion;
use App\Models\Zone;

/**
 * Seul endroit où le per diem est calculé (§ 11.4) :
 * PD = somme sur les zones et les types de quantité × tarif, avec le tarif
 * de la catégorie du missionnaire et de la zone de consommation.
 */
class PerDiemCalculator
{
    /**
     * @param  iterable<Consumption>  $consumptions
     */
    public function calculate(?Category $category, iterable $consumptions, ?RateVersion $version): PerDiemResult
    {
        $zones = Zone::query()->orderBy('id')->pluck('code', 'id');
        $zoneTotals = $zones->mapWithKeys(fn (string $code) => [$code => 0])->all();
        $issues = [];

        if ($category === null) {
            $issues[] = 'Catégorie du missionnaire manquante : aucun tarif ne peut être retenu.';
        }
        if ($version === null) {
            $issues[] = 'Aucune version de barème ne s\'applique à cette date.';
        }

        $rates = $category === null || $version === null
            ? collect()
            : $version->rates()->where('category_id', $category->id)->get()
                ->keyBy(fn (Rate $rate) => $rate->consumption_type->value.'|'.$rate->zone_id);

        $lines = [];
        $total = 0;

        foreach ($consumptions as $consumption) {
            if ($consumption->quantity === 0) {
                continue;
            }

            $zoneCode = $zones[$consumption->zoneId] ?? null;
            $label = mb_strtolower($consumption->type->label());

            if ($zoneCode === null) {
                $issues[] = "Zone inconnue pour {$label}.";

                continue;
            }

            /** @var Rate|null $rate */
            $rate = $rates->get($consumption->type->value.'|'.$consumption->zoneId);
            $status = match (true) {
                $consumption->quantity < 0 => PerDiemLine::INVALID_QUANTITY,
                $rate !== null && ! $rate->is_applicable => PerDiemLine::NOT_APPLICABLE,
                $rate?->amount === null => PerDiemLine::MISSING_RATE,
                default => PerDiemLine::OK,
            };

            $zoneLabel = $zoneCode === Zone::NORD ? 'Zone Nord' : 'Autres zones';
            match ($status) {
                PerDiemLine::INVALID_QUANTITY => $issues[] = "Quantité négative pour {$label} ({$zoneLabel}).",
                PerDiemLine::NOT_APPLICABLE => $issues[] = "{$consumption->type->label()} sans objet pour la catégorie {$category?->label} ({$zoneLabel}).",
                PerDiemLine::MISSING_RATE => $category !== null && $version !== null
                    ? $issues[] = "Tarif non renseigné : {$label}, {$category->label}, {$zoneLabel}."
                    : null,
                default => null,
            };

            $unitAmount = $rate !== null && $rate->is_applicable ? $rate->amount : null;
            $amount = $status === PerDiemLine::OK ? $consumption->quantity * (int) $unitAmount : null;
            if ($amount !== null) {
                $zoneTotals[$zoneCode] += $amount;
                $total += $amount;
            }

            $lines[] = new PerDiemLine(
                $consumption->type,
                $consumption->zoneId,
                $zoneCode,
                $consumption->quantity,
                $unitAmount,
                $amount,
                $status,
            );
        }

        return new PerDiemResult($version, $lines, $zoneTotals, $total, $issues === [], $issues);
    }
}
