<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employé du référentiel : personne concernée par les OM, jamais utilisateur
 * de l'application.
 *
 * @property int $id
 * @property string $matricule
 * @property string $last_name
 * @property string|null $first_name
 * @property string|null $position
 * @property int|null $service_id
 * @property int|null $category_id
 * @property int|null $current_place_id
 * @property string|null $account_number
 * @property bool $is_validator
 * @property bool $is_active
 */
#[Fillable(['matricule', 'last_name', 'first_name', 'position', 'service_id', 'category_id', 'current_place_id', 'account_number', 'is_validator', 'is_active'])]
class Employee extends Model
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_validator' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Affectation actuelle.
     *
     * @return BelongsTo<Place, $this>
     */
    public function currentPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'current_place_id');
    }

    /**
     * @return HasMany<AssignmentHistory, $this>
     */
    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(AssignmentHistory::class);
    }

    /**
     * Recherche sur le matricule, le nom et le prénom, sans tenir compte de la casse.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';

        $query->where(function (Builder $query) use ($like) {
            $query->where('matricule', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$like])
                ->orWhereRaw("concat_ws(' ', last_name, first_name) ilike ?", [$like]);
        });
    }
}
