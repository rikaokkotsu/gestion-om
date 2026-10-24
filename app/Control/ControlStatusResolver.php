<?php

namespace App\Control;

use App\Enums\AnomalyState;
use App\Enums\ControlStatus;
use App\Models\Anomaly;
use App\Models\MissionOrder;

/**
 * Statut de contrôle d'un OM, déduit de ses anomalies :
 * - une anomalie proposée attend une décision : contrôle en cours ;
 * - une anomalie confirmée, même transmise ou clôturée depuis : avec anomalies ;
 * - sinon, contrôlé sans anomalie s'il a été contrôlé au moins une fois.
 */
class ControlStatusResolver
{
    public function resolve(MissionOrder $order): ControlStatus
    {
        $anomalies = Anomaly::query()->where('mission_order_id', $order->id);

        return match (true) {
            (clone $anomalies)->where('state', AnomalyState::Proposed)->exists() => ControlStatus::InProgress,
            (clone $anomalies)->whereNotNull('confirmed_at')->where('state', '!=', AnomalyState::Dismissed)->exists() => ControlStatus::WithAnomalies,
            $order->last_controlled_at !== null => ControlStatus::NoAnomaly,
            default => ControlStatus::NotControlled,
        };
    }

    /**
     * Enregistre le statut sans changer la date de modification de l'OM :
     * le contrôle ne modifie pas le document contrôlé.
     */
    public function refresh(MissionOrder $order): void
    {
        $order->control_status = $this->resolve($order);

        MissionOrder::withoutTimestamps(fn () => $order->saveQuietly());
    }
}
