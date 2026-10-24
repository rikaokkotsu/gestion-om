<?php

namespace App\Models;

use App\Enums\AnomalyEventType;
use App\Enums\AnomalyState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrée de l'historique d'une anomalie (§ 13.4), en ajout seul. Sans
 * utilisateur, l'entrée vient du contrôle automatique.
 *
 * @property int $id
 * @property int $anomaly_id
 * @property int|null $user_id
 * @property AnomalyEventType $type
 * @property AnomalyState|null $from_state
 * @property AnomalyState|null $to_state
 * @property string|null $comment
 * @property array<string, mixed>|null $changes
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'type', 'from_state', 'to_state', 'comment', 'changes'])]
class AnomalyEvent extends Model
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
            'type' => AnomalyEventType::class,
            'from_state' => AnomalyState::class,
            'to_state' => AnomalyState::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Anomaly, $this>
     */
    public function anomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
