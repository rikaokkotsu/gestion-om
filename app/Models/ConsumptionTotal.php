<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Total attribué pour un type de consommation, tel que déclaré.
 *
 * @property int $id
 * @property int $mission_order_employee_id
 * @property ConsumptionType $consumption_type
 * @property int $total
 */
#[Fillable(['consumption_type', 'total'])]
class ConsumptionTotal extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['consumption_type' => ConsumptionType::class, 'total' => 'integer'];
    }
}
