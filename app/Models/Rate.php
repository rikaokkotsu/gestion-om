<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use App\Models\Concerns\Auditable;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarif d'un type de consommation pour une catégorie et une zone. Trois
 * états distincts : montant (0 compris), non renseigné (montant vide),
 * sans objet (`is_applicable` faux).
 *
 * @property int $id
 * @property int $rate_version_id
 * @property int $category_id
 * @property int $zone_id
 * @property ConsumptionType $consumption_type
 * @property int|null $amount
 * @property bool $is_applicable
 */
#[Fillable(['rate_version_id', 'category_id', 'zone_id', 'consumption_type', 'amount', 'is_applicable'])]
class Rate extends Model implements DescribedInAudit
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumption_type' => ConsumptionType::class,
            'amount' => 'integer',
            'is_applicable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RateVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RateVersion::class, 'rate_version_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function auditLabel(): string
    {
        return implode(' · ', [$this->category->label, $this->zone->label, $this->consumption_type->label()]).' ('.$this->version->label.')';
    }
}
