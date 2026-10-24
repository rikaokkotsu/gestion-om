<?php

namespace App\Models;

use App\Enums\CostNature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ligne de ventilation des frais supplémentaires d'un OM.
 *
 * @property int $id
 * @property int $mission_order_id
 * @property int $position
 * @property CostNature $nature
 * @property string|null $label
 * @property int|null $amount
 */
#[Fillable(['position', 'nature', 'label', 'amount'])]
class MissionOrderCost extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nature' => CostNature::class, 'amount' => 'integer'];
    }
}
