<?php

namespace App\Models;

use App\Enums\AssignmentSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Changement d'affectation d'un employé. Historique en ajout seul.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $previous_place_id
 * @property int|null $new_place_id
 * @property Carbon|null $effective_date
 * @property AssignmentSource $source
 * @property int|null $mission_order_id
 * @property int|null $recorded_by_id
 * @property Carbon $created_at
 */
#[Fillable(['employee_id', 'previous_place_id', 'new_place_id', 'effective_date', 'source', 'mission_order_id', 'recorded_by_id'])]
class AssignmentHistory extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'source' => AssignmentSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function previousPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'previous_place_id');
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function newPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'new_place_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
