<?php

namespace App\Actions;

use App\Control\ControlStatusResolver;
use App\Enums\AnomalyEventType;
use App\Enums\AnomalyState;
use App\Models\Anomaly;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change l'état de traitement d'une anomalie. Écarter demande un motif.
 */
class TransitionAnomaly
{
    public function __construct(private readonly ControlStatusResolver $statuses) {}

    public function __invoke(Anomaly $anomaly, AnomalyState $state, ?string $comment, ?User $actor): Anomaly
    {
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        if (! $anomaly->state->canBecome($state)) {
            throw ValidationException::withMessages([
                'state' => "Une anomalie à l'état « {$anomaly->state->label()} » ne peut pas passer à l'état « {$state->label()} ».",
            ]);
        }
        if ($state === AnomalyState::Dismissed && $comment === null) {
            throw ValidationException::withMessages(['comment' => 'Indiquez le motif pour lequel l\'anomalie est écartée.']);
        }

        return DB::transaction(function () use ($anomaly, $state, $comment, $actor) {
            $from = $anomaly->state;
            $anomaly->state = $state;
            if ($state === AnomalyState::Confirmed && $anomaly->confirmed_at === null) {
                $anomaly->confirmed_at = now();
            }
            $anomaly->save();

            $anomaly->events()->create([
                'user_id' => $actor?->id,
                'type' => AnomalyEventType::StateChanged,
                'from_state' => $from,
                'to_state' => $state,
                'comment' => $comment,
            ]);
            Audit::record('anomaly.state_changed', $anomaly, ['state' => $from->value], ['state' => $state->value, 'comment' => $comment], $actor);

            $this->statuses->refresh($anomaly->missionOrder);

            return $anomaly;
        });
    }
}
