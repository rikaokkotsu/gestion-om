<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Missionnaire d'un OM : ses quantités, son per diem déclaré et le calcul
 * de l'application, qui coexistent sans s'écraser.
 *
 * @property int $id
 * @property int $mission_order_id
 * @property int $employee_id
 * @property int $position
 * @property int|null $category_id
 * @property int|null $declared_per_diem
 * @property int|null $calculated_per_diem
 * @property bool|null $calculation_complete
 * @property array<string, mixed>|null $calculation
 * @property int|null $rate_version_id
 * @property Carbon|null $calculated_at
 */
#[Fillable(['employee_id', 'position', 'category_id', 'declared_per_diem'])]
class MissionOrderEmployee extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'declared_per_diem' => 'integer',
            'calculated_per_diem' => 'integer',
            'calculation_complete' => 'boolean',
            'calculation' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MissionOrder, $this>
     */
    public function missionOrder(): BelongsTo
    {
        return $this->belongsTo(MissionOrder::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Catégorie retenue sur l'OM.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<RateVersion, $this>
     */
    public function rateVersion(): BelongsTo
    {
        return $this->belongsTo(RateVersion::class);
    }

    /**
     * @return HasMany<ConsumptionQuantity, $this>
     */
    public function quantities(): HasMany
    {
        return $this->hasMany(ConsumptionQuantity::class)->orderBy('id');
    }

    /**
     * @return HasMany<ConsumptionTotal, $this>
     */
    public function totals(): HasMany
    {
        return $this->hasMany(ConsumptionTotal::class)->orderBy('id');
    }
}
