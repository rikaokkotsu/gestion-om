<?php

namespace App\Models;

use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\AnomalyPriority;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Situation d'un OM qui nécessite une vérification (§ 13.1), proposée par
 * le contrôle automatique ou signalée par un contrôleur. Elle ne modifie
 * jamais l'OM contrôlé.
 *
 * @property int $id
 * @property int $mission_order_id
 * @property int|null $mission_order_employee_id
 * @property int|null $employee_id
 * @property AnomalyCategory $category
 * @property string|null $rule_code
 * @property string $target
 * @property AnomalySource $source
 * @property AnomalyNature|null $nature
 * @property AnomalyPriority $priority
 * @property AnomalyState $state
 * @property string $description
 * @property int|null $reported_by_id
 * @property int|null $control_run_id
 * @property Carbon $detected_at
 * @property Carbon|null $last_detected_at
 * @property Carbon|null $no_longer_detected_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'mission_order_id', 'mission_order_employee_id', 'employee_id', 'category', 'rule_code', 'target', 'source', 'nature',
    'priority', 'state', 'description', 'reported_by_id', 'control_run_id', 'detected_at', 'last_detected_at',
    'no_longer_detected_at', 'confirmed_at',
])]
class Anomaly extends Model implements DescribedInAudit
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['target' => ''];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AnomalyCategory::class,
            'source' => AnomalySource::class,
            'nature' => AnomalyNature::class,
            'priority' => AnomalyPriority::class,
            'state' => AnomalyState::class,
            'detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'no_longer_detected_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * L'OM reste consultable depuis l'anomalie, même supprimé.
     *
     * @return BelongsTo<MissionOrder, $this>
     */
    public function missionOrder(): BelongsTo
    {
        return $this->belongsTo(MissionOrder::class)->withTrashed();
    }

    /**
     * @return BelongsTo<MissionOrderEmployee, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(MissionOrderEmployee::class, 'mission_order_employee_id');
    }

    /**
     * Employé concerné : un missionnaire, ou le validateur.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    /**
     * @return BelongsTo<ControlRun, $this>
     */
    public function controlRun(): BelongsTo
    {
        return $this->belongsTo(ControlRun::class);
    }

    /**
     * @return HasMany<AnomalyEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnomalyEvent::class)->orderBy('id');
    }

    /**
     * Anomalies dont l'OM n'est pas supprimé.
     *
     * @param  Builder<Anomaly>  $query
     */
    public function scopeOnActiveOrders(Builder $query): void
    {
        $query->whereHas('missionOrder', fn (Builder $order) => $order->whereNull('mission_orders.deleted_at'));
    }

    /**
     * @param  Builder<Anomaly>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('state', AnomalyState::openValues());
    }

    /**
     * Anomalies que l'OM présente : à traiter, ou clôturées après avoir été
     * confirmées. Les écartées et les propositions que le contrôle a closes
     * sans confirmation n'en font pas partie.
     *
     * @param  Builder<Anomaly>  $query
     */
    public function scopeRetained(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereIn('state', AnomalyState::openValues())
            ->orWhere(fn (Builder $query) => $query->where('state', AnomalyState::Closed)->whereNotNull('confirmed_at')));
    }

    public function auditLabel(): string
    {
        return "n° {$this->id}".($this->rule_code !== null ? " ({$this->rule_code})" : '');
    }
}
