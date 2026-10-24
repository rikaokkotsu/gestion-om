<?php

namespace App\Actions;

use App\Models\Rate;
use App\Models\RateVersion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Crée une version de barème à partir des tarifs d'une version existante :
 * par défaut celle en vigueur à la date d'effet, que la nouvelle remplace.
 */
class CreateRateVersion
{
    public function __invoke(string $label, Carbon $effectiveFrom, ?RateVersion $source, ?User $actor): RateVersion
    {
        $source ??= RateVersion::applicableOn($effectiveFrom);

        return DB::transaction(function () use ($label, $effectiveFrom, $source, $actor) {
            $version = RateVersion::create([
                'label' => $label,
                'effective_from' => $effectiveFrom->toDateString(),
                'created_by_id' => $actor?->id,
            ]);

            if ($source !== null) {
                $now = now();
                // Insertion groupée : la copie est journalisée une fois, pas tarif par tarif.
                $rows = $source->rates()->get()->map(fn (Rate $rate) => [
                    'rate_version_id' => $version->id,
                    'category_id' => $rate->category_id,
                    'zone_id' => $rate->zone_id,
                    'consumption_type' => $rate->consumption_type->value,
                    'amount' => $rate->amount,
                    'is_applicable' => $rate->is_applicable,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                Rate::query()->insert($rows);

                Audit::record('rate_version.copied', $version, [], [
                    'source_version_id' => $source->id,
                    'rates' => count($rows),
                ], $actor);
            }

            return $version;
        });
    }
}
