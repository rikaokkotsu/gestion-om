<?php

namespace App\Exports;

use App\Control\RuleRegistry;
use App\Models\Anomaly;
use Illuminate\Database\Eloquent\Builder;

/**
 * Export Excel de la liste des anomalies, avec les mêmes critères qu'à l'écran.
 */
final class AnomaliesExport
{
    public function __construct(private readonly RuleRegistry $rules) {}

    /**
     * @param  Builder<Anomaly>  $anomalies
     * @param  list<string>  $header  lignes de la feuille « Critères »
     * @return int nombre d'anomalies exportées
     */
    public function write(Builder $anomalies, array $header, string $path): int
    {
        $book = new Workbook($path);
        $book->sheet('anomalies', 'Anomalies', [
            'N°' => 'integer',
            'Détectée le' => 'datetime',
            'Référence de l\'OM' => 'text',
            'Type d\'OM' => 'text',
            'Départ de l\'OM' => 'datetime',
            'Matricule' => 'text',
            'Missionnaire' => 'text',
            'Catégorie' => 'text',
            'Règle' => 'text',
            'Libellé de la règle' => 'text',
            'Description' => 'long',
            'Nature' => 'text',
            'Priorité' => 'text',
            'État' => 'text',
            'Origine' => 'text',
            'Signalée par' => 'text',
            'Confirmée le' => 'datetime',
            'Plus détectée depuis le' => 'datetime',
        ]);

        $count = 0;
        foreach ((clone $anomalies)->with(['missionOrder', 'employee', 'reportedBy'])->lazy(500) as $anomaly) {
            $count++;
            $order = $anomaly->missionOrder;
            $book->row('anomalies', [
                $anomaly->id,
                $anomaly->detected_at,
                $order->reference ?? "sans référence (n° {$order->id})",
                $order->type?->label(),
                $order->departure_at,
                $anomaly->employee?->matricule,
                $anomaly->employee === null ? 'L\'OM' : trim("{$anomaly->employee->last_name} {$anomaly->employee->first_name}"),
                "{$anomaly->category->value}. {$anomaly->category->label()}",
                $anomaly->rule_code,
                $anomaly->rule_code === null ? null : $this->rules->find($anomaly->rule_code)?->definition()->label,
                $anomaly->description,
                $anomaly->nature?->label(),
                $anomaly->priority->label(),
                $anomaly->state->label(),
                $anomaly->source->label(),
                $anomaly->reportedBy?->name,
                $anomaly->confirmed_at,
                $anomaly->no_longer_detected_at,
            ]);
        }

        $book->notes('Critères', [...$header, "Anomalies exportées : {$count}"]);
        $book->close();

        return $count;
    }
}
