@extends('reports.layout', ['title' => 'Rapport de contrôle '.$order->auditLabel()])
@use('App\Reports\Format')
@use('App\Exports\MissionOrdersExport')

@section('content')
    <h1>Rapport de contrôle de l'OM {{ $order->auditLabel() }}</h1>
    <p class="muted">Édité le {{ Format::datetime($printedAt) }}@if ($actor) par {{ $actor->name }}@endif, d'après les données enregistrées. Les montants déclarés sont ceux du document ; les montants calculés sont ceux de l'application.</p>

    @if ($order->last_controlled_at === null)
        <p class="notice">Cet OM n'a pas encore été contrôlé : la liste des anomalies peut être incomplète.</p>
    @elseif ($order->changedSinceControl())
        <p class="notice">{{ $order->control_status->label() }} le {{ Format::datetime($order->last_controlled_at) }}. L'OM a été modifié depuis : les anomalies peuvent ne plus refléter son contenu.</p>
    @else
        <p class="{{ $groups['À traiter']->isEmpty() ? 'ok' : 'notice' }}">{{ $order->control_status->label() }} le {{ Format::datetime($order->last_controlled_at) }}.
            {{ $groups['À traiter']->isEmpty() ? 'Aucune anomalie à traiter.' : Format::count($groups['À traiter']->count(), 'anomalie').' à traiter.' }}</p>
    @endif

    <h2>Informations administratives</h2>
    <table class="fields">
        <tr><td class="label">Référence</td><td>{{ $order->reference ?? 'non renseignée' }}</td><td class="label">Type</td><td>{{ $order->type?->label() ?? 'non renseigné' }}</td></tr>
        <tr><td class="label">Objet</td><td colspan="3">{{ $order->missionObject?->label ?? 'non renseigné' }}</td></tr>
        <tr><td class="label">Départ</td><td>{{ Format::datetime($order->departure_at) }}</td><td class="label">Retour</td><td>{{ Format::datetime($order->return_at) }}</td></tr>
        <tr>
            <td class="label">Lieu de départ</td><td>{{ $order->departurePlace?->name ?? '—' }}@if ($order->departurePlace?->zone) <span class="muted">({{ $order->departurePlace->zone->label }})</span>@endif</td>
            <td class="label">Destination</td><td>{{ $order->destinationPlace?->name ?? '—' }}@if ($order->destinationPlace?->zone) <span class="muted">({{ $order->destinationPlace->zone->label }})</span>@endif</td>
        </tr>
        <tr><td class="label">Durée déclarée</td><td>{{ Format::days($order->declared_duration) }}</td><td class="label">Durée selon les dates</td><td>{{ Format::days($order->calculatedDuration()) }} <span class="muted small">({{ $durationConvention }})</span></td></tr>
        <tr><td class="label">Validateur</td><td>{{ Format::employee($order->validator) }}</td><td class="label">Validé le</td><td>{{ Format::datetime($order->validated_at) }}</td></tr>
        <tr><td class="label">État de validation</td><td>{{ $order->administrative_status->label() }}</td><td class="label">Indemnité d'affectation</td><td>{{ Format::amount($order->assignment_allowance) }}</td></tr>
        @if ($order->notes)
            <tr><td class="label">Observations</td><td colspan="3">{{ $order->notes }}</td></tr>
        @endif
    </table>

    <h2>Missionnaires et per diem</h2>
    @if ($members->isEmpty())
        <p class="muted">Aucun missionnaire enregistré.</p>
    @else
        <table>
            <thead>
                <tr><th>Missionnaire</th><th>Catégorie sur l'OM</th><th class="num">Per diem déclaré</th><th class="num">Per diem calculé</th><th class="num">Écart</th><th>Calcul</th></tr>
            </thead>
            <tbody>
                @foreach ($members as $member)
                    @php($complete = $member->calculation_complete === true)
                    <tr>
                        <td>{{ Format::employee($member->employee) }}</td>
                        <td>{{ $member->category?->code ?? 'non renseignée' }}</td>
                        <td class="num">{{ Format::amount($member->declared_per_diem) }}</td>
                        <td class="num">{{ $complete ? Format::amount($member->calculated_per_diem) : '—' }}</td>
                        <td class="num {{ $complete && $member->declared_per_diem !== null && $member->declared_per_diem !== $member->calculated_per_diem ? 'warn' : '' }}">
                            {{ $complete && $member->declared_per_diem !== null ? Format::amount($member->declared_per_diem - (int) $member->calculated_per_diem) : '—' }}
                        </td>
                        <td class="{{ $complete ? '' : 'warn' }}">{{ MissionOrdersExport::memberCalculation($member) }}@if ($member->rateVersion)<br><span class="muted small">{{ $member->rateVersion->label }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total</td>
                    <td class="num">{{ Format::amount($declaredTotal) }}</td>
                    <td class="num">{{ $calculatedTotal === null ? 'incomplet' : Format::amount($calculatedTotal) }}</td>
                    <td class="num">{{ $gapTotal === null ? '—' : Format::amount($gapTotal) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        @foreach ($members as $member)
            @if (! empty($member->calculation['lines']))
                <div style="page-break-inside: avoid">
                <h3>Détail du calcul : {{ Format::employee($member->employee) }}</h3>
                <table>
                    <thead><tr><th>Consommation</th><th>Zone</th><th class="num">Quantité</th><th class="num">Tarif</th><th class="num">Montant</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($member->calculation['lines'] as $line)
                            <tr>
                                <td>{{ $line['consumption_label'] }}</td>
                                <td>{{ $zones[$line['zone_code']] ?? $line['zone_code'] }}</td>
                                <td class="num">{{ $line['quantity'] }}</td>
                                <td class="num">{{ Format::amount($line['rate']) }}</td>
                                <td class="num">{{ Format::amount($line['amount']) }}</td>
                                <td class="warn small">{{ ['missing_rate' => 'Tarif non renseigné', 'not_applicable' => 'Sans objet', 'invalid_quantity' => 'Quantité invalide'][$line['status']] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        @endforeach
    @endif

    <h2>Frais supplémentaires</h2>
    <p>Montant global déclaré : <strong>{{ Format::amount($order->additional_costs) }}</strong>, pour l'ensemble des missionnaires.</p>
    @if ($order->costs->isNotEmpty())
        <table>
            <thead><tr><th>Nature</th><th>Libellé</th><th class="num">Montant</th></tr></thead>
            <tbody>
                @foreach ($order->costs as $cost)
                    <tr><td>{{ $cost->nature->label() }}</td><td>{{ $cost->label ?? '—' }}</td><td class="num">{{ Format::amount($cost->amount) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="2">Total de la ventilation</td><td class="num">{{ Format::amount($costsTotal) }}</td></tr></tfoot>
        </table>
    @endif

    <h2>Transports</h2>
    @if ($order->legs->isEmpty())
        <p class="muted">Aucune étape de transport enregistrée.</p>
    @else
        <table>
            <thead><tr><th>Sens</th><th class="num">Étape</th><th>Moyen</th><th>De</th><th>À</th><th class="num">Coût</th></tr></thead>
            <tbody>
                @foreach ($order->legs as $leg)
                    <tr>
                        <td>{{ $leg->direction->label() }}</td>
                        <td class="num">{{ $leg->position }}</td>
                        <td>{{ $leg->transportMode?->label ?? '—' }}</td>
                        <td>{{ $leg->fromPlace?->name ?? '—' }}</td>
                        <td>{{ $leg->toPlace?->name ?? '—' }}</td>
                        <td class="num">{{ Format::amount($leg->cost) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Anomalies</h2>
    @if ($order->anomalies->isEmpty())
        <p class="muted">Aucune anomalie enregistrée pour cet OM.</p>
    @endif
    @foreach ($groups as $title => $anomalies)
        @if ($anomalies->isNotEmpty())
            <h3>{{ $title }} ({{ $anomalies->count() }})</h3>
            <table>
                <thead><tr><th>N°</th><th>Catégorie</th><th>Concerne</th><th style="width: 46%">Description</th><th>Priorité</th><th>État</th></tr></thead>
                <tbody>
                    @foreach ($anomalies as $anomaly)
                        @php($comment = $lastComment($anomaly))
                        <tr>
                            <td>{{ $anomaly->id }}</td>
                            <td>{{ $anomaly->category->value }}. {{ $anomaly->category->label() }}@if ($anomaly->rule_code)<br><span class="mono small">{{ $anomaly->rule_code }}</span>@endif</td>
                            <td>{{ $anomaly->employee ? Format::employee($anomaly->employee) : "L'OM" }}</td>
                            <td>
                                {{ $anomaly->description }}
                                @if ($comment)
                                    <br><span class="muted small">Dernier commentaire ({{ $comment->user?->name ?? 'contrôle automatique' }}, {{ Format::date($comment->created_at) }}) : {{ $comment->comment }}</span>
                                @endif
                            </td>
                            <td>{{ $anomaly->priority->label() }}</td>
                            <td>{{ $anomaly->state->label() }}@if ($anomaly->no_longer_detected_at)<br><span class="small">plus détectée</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endsection
