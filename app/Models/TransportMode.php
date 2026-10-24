<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Moyen de déplacement. `generates_costs` porte la règle du § 9.3 : seuls
 * les moyens payants peuvent justifier des frais de transport.
 *
 * @property int $id
 * @property string $label
 * @property bool $generates_costs
 * @property bool $is_active
 */
#[Fillable(['label', 'generates_costs', 'is_active'])]
class TransportMode extends Model
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['generates_costs' => 'boolean', 'is_active' => 'boolean'];
    }
}
