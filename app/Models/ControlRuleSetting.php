<?php

namespace App\Models;

use App\Control\RuleRegistry;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Activation d'une règle du catalogue ; une règle sans réglage est active.
 *
 * @property int $id
 * @property string $code
 * @property bool $is_enabled
 * @property int|null $updated_by_id
 */
#[Fillable(['code', 'is_enabled', 'updated_by_id'])]
class ControlRuleSetting extends Model implements DescribedInAudit
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function auditLabel(): string
    {
        $label = app(RuleRegistry::class)->find($this->code)?->definition()->label;

        return $label === null ? $this->code : "{$this->code} : {$label}";
    }
}
