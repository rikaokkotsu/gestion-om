<?php

namespace App\Models;

use App\Enums\AdministrativeStatus;
use App\Enums\AnomalyState;
use App\Enums\ControlStatus;
use App\Enums\MissionOrderType;
use App\Models\Contracts\DescribedInAudit;
use App\Support\MissionDuration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Ordre de mission saisi tel qu'il figure sur le document. Les écarts aux
 * règles métier sont signalés par le moteur de contrôle, jamais corrigés.
 *
 * @property int $id
 * @property string|null $reference
 * @property MissionOrderType|null $type
 * @property int|null $mission_object_id
 * @property Carbon|null $departure_at
 * @property Carbon|null $return_at
 * @property int|null $departure_place_id
 * @property int|null $destination_place_id
 * @property int|null $declared_duration
 * @property int|null $additional_costs
 * @property int|null $assignment_allowance
 * @property int|null $validator_id
 * @property Carbon|null $validated_at
 * @property AdministrativeStatus $administrative_status
 * @property ControlStatus $control_status
 * @property string|null $notes
 * @property Carbon|null $last_controlled_at
 * @property int|null $created_by_id
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'reference', 'type', 'mission_object_id', 'departure_at', 'return_at', 'departure_place_id', 'destination_place_id',
    'declared_duration', 'additional_costs', 'assignment_allowance', 'validator_id', 'validated_at', 'administrative_status', 'notes',
])]
class MissionOrder extends Model implements DescribedInAudit
{
    use SoftDeletes;

    /**
     * Valeurs par défaut, identiques à celles de la base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'administrative_status' => 'PENDING',
        'control_status' => 'NOT_CONTROLLED',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MissionOrderType::class,
            'departure_at' => 'datetime',
            'return_at' => 'datetime',
            'validated_at' => 'datetime',
            'declared_duration' => 'integer',
            'additional_costs' => 'integer',
            'assignment_allowance' => 'integer',
            'administrative_status' => AdministrativeStatus::class,
            'control_status' => ControlStatus::class,
            'last_controlled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MissionObject, $this>
     */
    public function missionObject(): BelongsTo
    {
        return $this->belongsTo(MissionObject::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function departurePlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'departure_place_id');
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function destinationPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'destination_place_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'validator_id');
    }

    /**
     * @return HasMany<MissionOrderCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(MissionOrderCost::class)->orderBy('position');
    }

    /**
     * @return HasMany<TransportLeg, $this>
     */
    public function legs(): HasMany
    {
        // OUTBOUND précède RETURN dans l'ordre alphabétique : l'aller vient d'abord.
        return $this->hasMany(TransportLeg::class)->orderBy('direction')->orderBy('position');
    }

    /**
     * Missionnaires, dans l'ordre de saisie.
     *
     * @return HasMany<MissionOrderEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(MissionOrderEmployee::class)->orderBy('position');
    }

    /**
     * Changements d'affectation causés par cet OM.
     *
     * @return HasMany<AssignmentHistory, $this>
     */
    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(AssignmentHistory::class);
    }

    /**
     * @return HasMany<Anomaly, $this>
     */
    public function anomalies(): HasMany
    {
        return $this->hasMany(Anomaly::class);
    }

    /**
     * Anomalies encore à traiter.
     *
     * @return HasMany<Anomaly, $this>
     */
    public function openAnomalies(): HasMany
    {
        return $this->hasMany(Anomaly::class)->whereIn('state', AnomalyState::openValues());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * Durée selon les dates et la convention retenue ; null si une date manque.
     */
    public function calculatedDuration(): ?int
    {
        return $this->departure_at === null || $this->return_at === null
            ? null
            : MissionDuration::between($this->departure_at, $this->return_at);
    }

    /**
     * OM dont l'employé est missionnaire ou validateur.
     *
     * @param  Builder<MissionOrder>  $query
     */
    public function scopeConcerning(Builder $query, int $employeeId): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('validator_id', $employeeId)
            ->orWhereHas('employees', fn (Builder $member) => $member->where('employee_id', $employeeId)));
    }

    /**
     * Ordre des listes : départ le plus récent d'abord, OM sans date de départ à la fin.
     *
     * @param  Builder<MissionOrder>  $query
     */
    public function scopeLatestDeparture(Builder $query): void
    {
        $query->orderByRaw('departure_at desc nulls last')->orderByDesc('id');
    }

    /**
     * Modifié depuis le dernier contrôle : les anomalies affichées peuvent ne plus refléter l'OM.
     */
    public function changedSinceControl(): bool
    {
        return $this->last_controlled_at !== null && $this->updated_at !== null && $this->updated_at->greaterThan($this->last_controlled_at);
    }

    public function auditLabel(): string
    {
        return $this->reference ?? "sans référence (n° {$this->id})";
    }
}
