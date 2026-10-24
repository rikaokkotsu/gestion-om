<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Contracts\DescribedInAudit;
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
class Service extends Model implements DescribedInAudit
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
        return $this->name;
    }
}
