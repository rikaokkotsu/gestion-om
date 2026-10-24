<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Quantité attribuée à un missionnaire pour un type de consommation dans une zone.
 *
 * @property int $id
 * @property int $mission_order_employee_id
 * @property ConsumptionType $consumption_type
 * @property int $zone_id
 * @property int $quantity
 */
#[Fillable(['consumption_type', 'zone_id', 'quantity'])]
class ConsumptionQuantity extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['consumption_type' => ConsumptionType::class, 'quantity' => 'integer'];
    }
}
