<?php

namespace App\Models;

use App\Enums\TravelDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape d'un trajet aller ou retour, à sa place dans l'ordre du trajet.
 *
 * @property int $id
 * @property int $mission_order_id
 * @property TravelDirection $direction
 * @property int $position
 * @property int|null $transport_mode_id
 * @property int|null $from_place_id
 * @property int|null $to_place_id
 * @property int|null $cost
 */
#[Fillable(['direction', 'position', 'transport_mode_id', 'from_place_id', 'to_place_id', 'cost'])]
class TransportLeg extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['direction' => TravelDirection::class, 'cost' => 'integer'];
    }

    /**
     * @return BelongsTo<TransportMode, $this>
     */
    public function transportMode(): BelongsTo
    {
        return $this->belongsTo(TransportMode::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function fromPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'from_place_id');
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function toPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'to_place_id');
    }
}
