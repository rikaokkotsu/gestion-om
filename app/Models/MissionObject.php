<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Objet (motif) d'un OM, distinct de son type.
 *
 * @property int $id
 * @property string $label
 * @property bool $is_active
 */
#[Fillable(['label', 'is_active'])]
class MissionObject extends Model implements DescribedInAudit
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

    public function auditLabel(): string
    {
        return $this->label;
    }
}
