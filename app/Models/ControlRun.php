<?php

namespace App\Models;

use App\Enums\ControlRunStatus;
use App\Enums\ControlTrigger;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Exécution du moteur de contrôle, sur un OM ou sur un lot d'OM.
 *
 * @property int $id
 * @property ControlTrigger $trigger
 * @property ControlRunStatus $status
 * @property int|null $mission_order_id
 * @property array<string, mixed>|null $filters
 * @property int|null $launched_by_id
 * @property int $orders_count
 * @property int $processed_count
 * @property int $anomalies_created
 * @property int $anomalies_closed
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'trigger', 'status', 'mission_order_id', 'filters', 'launched_by_id', 'orders_count', 'processed_count',
    'anomalies_created', 'anomalies_closed', 'error', 'started_at', 'finished_at',
])]
class ControlRun extends Model implements DescribedInAudit
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'QUEUED',
        'orders_count' => 0,
        'processed_count' => 0,
        'anomalies_created' => 0,
        'anomalies_closed' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => ControlTrigger::class,
            'status' => ControlRunStatus::class,
            'filters' => 'array',
            'orders_count' => 'integer',
            'processed_count' => 'integer',
            'anomalies_created' => 'integer',
            'anomalies_closed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MissionOrder, $this>
     */
    public function missionOrder(): BelongsTo
    {
        return $this->belongsTo(MissionOrder::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function launchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by_id');
    }

    public function auditLabel(): string
    {
        return "n° {$this->id}";
    }
}
