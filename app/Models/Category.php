<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie professionnelle (DG, DGA, R HC3…), clé des barèmes de per diem.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property bool $is_active
 */
#[Fillable(['code', 'label', 'is_active'])]
class Category extends Model implements DescribedInAudit
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

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function auditLabel(): string
    {
        return $this->label;
    }
}
