@extends('reports.layout', ['title' => 'Récapitulatif des OM'])
@use('App\Reports\Format')

@section('content')
    @php($o = $figures['orders'])
    @php($pd = $figures['per_diem'])
    @php($costs = $figures['costs'])
    @php($status = collect($o['by_administrative_status'])->pluck('count', 'value'))

    <h1>Récapitulatif des ordres de mission</h1>
    <p class="muted">Édité le {{ Format::datetime($printedAt) }}@if ($actor) par {{ $actor->name }}@endif, d'après les données enregistrées (OM supprimés exclus).</p>
    <p><strong>{{ $criteria === [] ? 'Tous les OM enregistrés.' : 'Critères : ' }}</strong>{{ implode(' ; ', $criteria) }}</p>

    <h2>Indicateurs</h2>
    <table class="tiles">
        <tr>
            <td><div class="muted">OM enregistrés</div><div class="value">{{ Format::number($o['total']) }}</div></td>
            <td><div class="muted">En attente de validation</div><div class="value">{{ Format::number($status['PENDING'] ?? 0) }}</div></td>
            <td><div class="muted">Validés</div><div class="value">{{ Format::number($status['VALIDATED'] ?? 0) }}</div></td>
            <td><div class="muted">Présentant des anomalies</div><div class="value">{{ Format::number($o['with_anomalies']) }}</div><div class="small muted">dont {{ Format::number($o['with_open_anomalies']) }} avec anomalies à traiter</div></td>
        </tr>
        <tr>
            <td><div class="muted">Per diem calculés</div><div class="value">{{ $pd['complete'] > 0 ? Format::amount($pd['calculated']) : '—' }}</div><div class="small muted">{{ Format::count($pd['complete'], 'calcul complet', 'calculs complets') }}</div></td>
            <td><div class="muted">Per diem déclarés</div><div class="value">{{ $pd['declared_count'] > 0 ? Format::amount($pd['declared']) : '—' }}</div><div class="small muted">{{ Format::count($pd['declared_count'], 'missionnaire') }}</div></td>
            <td><div class="muted">Frais supplémentaires</div><div class="value">{{ $costs['orders_with_costs'] > 0 ? Format::amount($costs['additional_costs']) : '—' }}</div><div class="small muted">{{ Format::count($costs['orders_with_costs'], 'OM', 'OM') }}</div></td>
            <td><div class="muted">Indemnités d'affectation</div><div class="value">{{ $costs['orders_with_allowance'] > 0 ? Format::amount($costs['assignment_allowance']) : '—' }}</div><div class="small muted">{{ Format::count($costs['orders_with_allowance'], 'OM', 'OM') }}</div></td>
        </tr>
    </table>
    @if ($pd['incomplete'] > 0 || $pd['not_calculated'] > 0)
        <p class="notice">Le total calculé ne comprend que les calculs complets.
            @if ($pd['incomplete'] > 0) {{ Format::count($pd['incomplete'], 'missionnaire') }} {{ $pd['incomplete'] > 1 ? 'ont' : 'a' }} un calcul incomplet (tarif manquant ou quantité invalide), non compté. @endif
            @if ($pd['not_calculated'] > 0) {{ Format::count($pd['not_calculated'], 'missionnaire') }} sans calcul. @endif
        </p>
    @endif
    @if ($pd['compared'] > 0)
        <p>Sur {{ Format::count($pd['compared'], 'missionnaire') }} dont le per diem est à la fois déclaré et calculé, {{ Format::number($pd['mismatches']) }} présente{{ $pd['mismatches'] > 1 ? 'nt' : '' }} un écart ; écart cumulé déclaré − calculé : {{ Format::amount($pd['gap']) }}.</p>
    @endif

    <h2>Répartition des OM</h2>
    @php($breakdowns = ['Type' => $o['by_type'], 'État de validation' => $o['by_administrative_status'], 'Contrôle' => $o['by_control_status']])
    @php($lines = max(array_map('count', $breakdowns)))
    <table>
        <thead>
            <tr>
                @foreach ($breakdowns as $title => $rows)
                    <th>{{ $title }}</th><th class="num">OM</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $lines; $i++)
                <tr>
                    @foreach ($breakdowns as $rows)
                        <td>{{ $rows[$i]['label'] ?? '' }}</td><td class="num">{{ isset($rows[$i]) ? Format::number($rows[$i]['count']) : '' }}</td>
                    @endforeach
                </tr>
            @endfor
        </tbody>
    </table>

    <h2>Anomalies par catégorie</h2>
    <p class="small muted">Anomalies retenues : à traiter, ou clôturées après confirmation. Les anomalies écartées n'y figurent pas.</p>
    @php($max = max(1, ...array_column($figures['anomalies']['by_category'], 'count')))
    <table>
        <thead><tr><th style="width: 22%">Catégorie</th><th class="num" style="width: 10%">Retenues</th><th class="num" style="width: 10%">À traiter</th><th></th></tr></thead>
        <tbody>
            @foreach ($figures['anomalies']['by_category'] as $row)
                <tr>
                    <td>{{ $row['value'] }}. {{ $row['label'] }}</td>
                    <td class="num">{{ Format::number($row['count']) }}</td>
                    <td class="num">{{ Format::number($row['open']) }}</td>
                    <td>@if ($row['count'] > 0)<div class="bar" style="width: {{ round($row['count'] / $max * 100) }}%"></div>@endif</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td>Total</td><td class="num">{{ Format::number($figures['anomalies']['total']) }}</td><td class="num">{{ Format::number($figures['anomalies']['open']) }}</td><td></td></tr></tfoot>
    </table>

    <h2>Liste des OM</h2>
    @if ($orders->count() < $o['total'])
        <p class="notice">La liste montre les {{ Format::number($orders->count()) }} premiers OM sur {{ Format::number($o['total']) }}. L'export Excel les contient tous.</p>
    @endif
    @if ($orders->isEmpty())
        <p class="muted">Aucun OM ne correspond.</p>
    @else
        <table>
            <thead>
                <tr><th>Référence</th><th>Type</th><th>Départ</th><th>Destination</th><th>Missionnaires</th><th>Validation</th><th>Contrôle</th><th class="num">À traiter</th><th class="num">Per diem déclaré</th><th class="num">Per diem calculé</th><th class="num">Frais suppl.</th></tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    @php($members = $order->employees)
                    @php($complete = $members->isNotEmpty() && $members->every(fn ($member) => $member->calculation_complete === true))
                    <tr>
                        <td class="mono">{{ $order->reference ?? 'sans référence' }}</td>
                        <td>{{ $order->type?->label() ?? '—' }}</td>
                        <td>{{ Format::date($order->departure_at) }}</td>
                        <td>{{ $order->destinationPlace?->name ?? '—' }}</td>
                        <td>{{ $members->map(fn ($member) => trim($member->employee->last_name.' '.$member->employee->first_name))->implode(', ') ?: '—' }}</td>
                        <td>{{ $order->administrative_status->label() }}</td>
                        <td>{{ $order->control_status->label() }}</td>
                        <td class="num">{{ $order->open_anomalies_count ?: '' }}</td>
                        <td class="num">{{ Format::amount($members->whereNotNull('declared_per_diem')->isEmpty() ? null : (int) $members->sum('declared_per_diem')) }}</td>
                        <td class="num">{{ $complete ? Format::amount((int) $members->sum('calculated_per_diem')) : ($members->isEmpty() ? '—' : 'incomplet') }}</td>
                        <td class="num">{{ Format::amount($order->additional_costs) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
