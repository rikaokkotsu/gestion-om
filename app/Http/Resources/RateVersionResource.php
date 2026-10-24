<?php

namespace App\Http\Resources;

use App\Models\Rate;
use App\Models\RateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RateVersion
 */
class RateVersionResource extends JsonResource
{
    private ?RateVersion $next = null;

    private ?int $expectedRates = null;

    /**
     * La période de validité dépend de la version suivante, et le nombre de
     * tarifs manquants de la taille d'une grille complète.
     */
    public function withContext(?RateVersion $next, int $expectedRates): static
    {
        $this->next = $next;
        $this->expectedRates = $expectedRates;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->next?->effective_from?->copy()->subDay()->toDateString(),
            'is_locked' => $this->isLocked(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'missing_count' => $this->whenHas('filled_count', fn (int $filled) => max(0, (int) $this->expectedRates - $filled)),
            'rates' => $this->whenLoaded('rates', fn () => $this->rates->map(fn (Rate $rate) => [
                'category_id' => $rate->category_id,
                'zone_id' => $rate->zone_id,
                'consumption_type' => $rate->consumption_type->value,
                'amount' => $rate->amount,
                'is_applicable' => $rate->is_applicable,
            ])->values()),
        ];
    }
}
