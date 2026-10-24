<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Service auquel un employé est rattaché.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 */
#[Fillable(['name', 'is_active'])]
class Service extends Model
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
