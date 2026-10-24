<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zone géographique utilisée par les barèmes de per diem. Les deux zones
 * (NORD, AUTRES) sont créées par migration et ne se modifient pas.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 */
class Zone extends Model
{
    public const NORD = 'NORD';

    public const AUTRES = 'AUTRES';

    /**
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }
}
