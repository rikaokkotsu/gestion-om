<?php

namespace App\Exports;

use App\Models\Anomaly;
use App\Models\Employee;
use App\Models\MissionOrder;
use App\Models\MissionOrderCost;
use App\Models\MissionOrderEmployee;
use App\Models\TransportLeg;
use Illuminate\Database\Eloquent\Builder;

/**
 * Export Excel des OM retenus par une recherche : une feuille par sujet du
 * § 14.4 (OM, missionnaires et per diem, frais, transports, anomalies). Les
 * montants déclarés et calculés figurent côte à côte, sans s'écraser.
 */
final class MissionOrdersExport
{
    private const RELATIONS = [
        'missionObject', 'departurePlace', 'destinationPlace', 'validator',
        'costs', 'legs.transportMode', 'legs.fromPlace', 'legs.toPlace',
        'employees.employee.service', 'employees.category', 'employees.rateVersion',
        'anomalies.employee',
    ];

    /**
     * @param  Builder<MissionOrder>  $orders
     * @param  list<string>  $header  lignes de la feuille « Critères »
     * @return int nombre d'OM exportés
     */
    public static function write(Builder $orders, array $header, string $path): int
    {
        $book = new Workbook($path);
        $book->sheet('orders', 'OM', [
            'Référence' => 'text',
            'Type' => 'text',
            'Objet' => 'text',
            'Départ' => 'datetime',
            'Retour' => 'datetime',
            'Lieu de départ' => 'text',
            'Destination' => 'text',
            'Durée déclarée (jours)' => 'integer',
            'Durée selon les dates (jours)' => 'integer',
            'Missionnaires' => 'integer',
            'Validateur' => 'text',
            'Validé le' => 'datetime',
            'État de validation' => 'text',
            'Contrôle' => 'text',
            'Anomalies à traiter' => 'integer',
            'Per diem déclaré (Ar)' => 'amount',
            'Per diem calculé (Ar)' => 'amount',
            'Calcul du per diem' => 'text',
            'Frais supplémentaires (Ar)' => 'amount',
            'Indemnité d\'affectation (Ar)' => 'amount',
            'Observations' => 'long',
        ]);
        $book->sheet('members', 'Missionnaires', [
            'Référence de l\'OM' => 'text',
            'Départ' => 'datetime',
            'Matricule' => 'text',
            'Nom' => 'text',
            'Prénom' => 'text',
            'Service' => 'text',
            'Catégorie sur l\'OM' => 'text',
            'Per diem déclaré (Ar)' => 'amount',
            'Per diem calculé (Ar)' => 'amount',
            'Écart déclaré − calculé (Ar)' => 'amount',
            'Calcul' => 'long',
            'Barème' => 'text',
        ]);
        $book->sheet('costs', 'Frais', [
            'Référence de l\'OM' => 'text',
            'Nature' => 'text',
            'Libellé' => 'text',
            'Montant (Ar)' => 'amount',
        ]);
        $book->sheet('legs', 'Transports', [
            'Référence de l\'OM' => 'text',
            'Sens' => 'text',
            'Étape' => 'integer',
            'Moyen de transport' => 'text',
            'De' => 'text',
            'À' => 'text',
            'Coût (Ar)' => 'amount',
        ]);
        $book->sheet('anomalies', 'Anomalies', [
            'Référence de l\'OM' => 'text',
            'N°' => 'integer',
            'Catégorie' => 'text',
            'Règle' => 'text',
            'Concerne' => 'text',
            'Description' => 'long',
            'Priorité' => 'text',
            'État' => 'text',
            'Origine' => 'text',
            'Détectée le' => 'datetime',
        ]);

        $count = 0;
        $query = (clone $orders)->with(self::RELATIONS)->withCount('openAnomalies')->latestDeparture();
        foreach ($query->lazy(200) as $order) {
            $count++;
            $reference = $order->reference ?? "sans référence (n° {$order->id})";
            $members = $order->employees;

            $book->row('orders', [
                $reference,
                $order->type?->label(),
                $order->missionObject?->label,
                $order->departure_at,
                $order->return_at,
                $order->departurePlace?->name,
                $order->destinationPlace?->name,
                $order->declared_duration,
                $order->calculatedDuration(),
                $members->count(),
                self::employee($order->validator),
                $order->validated_at,
                $order->administrative_status->label(),
                $order->control_status->label(),
                (int) $order->getAttribute('open_anomalies_count'),
                $members->whereNotNull('declared_per_diem')->isEmpty() ? null : (int) $members->sum('declared_per_diem'),
                self::orderPerDiem($order),
                self::orderCalculation($order),
                $order->additional_costs,
                $order->assignment_allowance,
                $order->notes,
            ]);

            foreach ($members as $member) {
                $complete = $member->calculation_complete === true;
                $book->row('members', [
                    $reference,
                    $order->departure_at,
                    $member->employee->matricule,
                    $member->employee->last_name,
                    $member->employee->first_name,
                    $member->employee->service?->name,
                    $member->category?->code,
                    $member->declared_per_diem,
                    $complete ? $member->calculated_per_diem : null,
                    $complete && $member->declared_per_diem !== null ? $member->declared_per_diem - (int) $member->calculated_per_diem : null,
                    self::memberCalculation($member),
                    $member->rateVersion?->label,
                ]);
            }

            foreach ($order->costs as $cost) {
                /** @var MissionOrderCost $cost */
                $book->row('costs', [$reference, $cost->nature->label(), $cost->label, $cost->amount]);
            }

            foreach ($order->legs as $leg) {
                /** @var TransportLeg $leg */
                $book->row('legs', [
                    $reference,
                    $leg->direction->label(),
                    $leg->position,
                    $leg->transportMode?->label,
                    $leg->fromPlace?->name,
                    $leg->toPlace?->name,
                    $leg->cost,
                ]);
            }

            foreach ($order->anomalies->sortBy('id') as $anomaly) {
                /** @var Anomaly $anomaly */
                $book->row('anomalies', [
                    $reference,
                    $anomaly->id,
                    "{$anomaly->category->value}. {$anomaly->category->label()}",
                    $anomaly->rule_code,
                    $anomaly->employee === null ? 'L\'OM' : self::employee($anomaly->employee),
                    $anomaly->description,
                    $anomaly->priority->label(),
                    $anomaly->state->label(),
                    $anomaly->source->label(),
                    $anomaly->detected_at,
                ]);
            }
        }

        $book->notes('Critères', [...$header, "OM exportés : {$count}"]);
        $book->close();

        return $count;
    }

    public static function employee(?Employee $employee): ?string
    {
        return $employee?->auditLabel();
    }

    /**
     * Total calculé de l'OM, seulement si chaque missionnaire a un calcul complet.
     */
    private static function orderPerDiem(MissionOrder $order): ?int
    {
        $members = $order->employees;

        return $members->isNotEmpty() && $members->every(fn (MissionOrderEmployee $member) => $member->calculation_complete === true)
            ? (int) $members->sum('calculated_per_diem')
            : null;
    }

    private static function orderCalculation(MissionOrder $order): string
    {
        $members = $order->employees;
        $incomplete = $members->filter(fn (MissionOrderEmployee $member) => $member->calculation_complete !== true)->count();

        return match (true) {
            $members->isEmpty() => 'Aucun missionnaire',
            $incomplete === 0 => 'Complet',
            default => "Incomplet pour {$incomplete} missionnaire".($incomplete > 1 ? 's' : ''),
        };
    }

    public static function memberCalculation(MissionOrderEmployee $member): string
    {
        if ($member->calculation_complete === null) {
            return 'Non calculé';
        }
        if ($member->calculation_complete) {
            return 'Complet';
        }

        /** @var list<string> $issues */
        $issues = $member->calculation['issues'] ?? [];

        return 'Incomplet'.($issues === [] ? '' : ' : '.implode(' ', $issues));
    }
}
