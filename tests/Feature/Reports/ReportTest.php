<?php

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\MissionOrder;
use App\Reports\MissionOrderReport;
use App\Reports\SummaryReport;
use App\Search\MissionOrderSearch;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
    $this->travelTo('2026-10-24 14:05');
});

it('édite le rapport de contrôle d\'un OM en PDF et l\'inscrit au journal', function () {
    $order = $this->saveOrder();
    $this->control($order);

    $response = $this->get("/api/reports/mission-orders/{$order->id}")->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('rapport-controle-om-2026-001.pdf')
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');

    $log = AuditLog::where('action', 'report.mission_order')->sole();
    expect($log->auditable_id)->toBe($order->id)
        ->and($log->auditable_type)->toBe('mission_order');
});

it('montre dans le rapport les montants déclarés et calculés, et les anomalies avec leur traitement', function () {
    $order = $this->saveOrder([
        'declared_duration' => 4,
        'assignment_allowance' => 300000,
        'employees' => [$this->member($this->rakoto), $this->member($this->rabe, ['category_id' => null, 'declared_per_diem' => 100000])],
    ]);
    $this->control($order);
    $dismissed = Anomaly::where('rule_code', 'AFF-01')->value('id');
    $this->postJson("/api/anomalies/{$dismissed}/transition", ['state' => 'DISMISSED', 'comment' => 'Convention particulière.'])->assertOk();

    $html = view('reports.mission-order', MissionOrderReport::data(MissionOrder::findOrFail($order->id), $this->user))->render();

    expect($html)
        ->toContain('Rapport de contrôle de l\'OM OM-2026-001')
        ->toContain('par '.e($this->user->name))
        ->toContain('503 000 Ar')
        ->toContain('1 250 000 Ar')
        ->toContain('Incomplet')
        // Le total calculé de l'OM n'est pas donné tant qu'un calcul est incomplet.
        ->toContain('<td class="num">incomplet</td>')
        ->toContain('Durée selon les dates')
        ->toContain('À traiter (')
        ->toContain('Écartées, ou closes par le contrôle sans confirmation (1)')
        ->toContain(e('Convention particulière.'));
});

it('signale dans le rapport un OM jamais contrôlé ou modifié depuis son contrôle', function () {
    $order = $this->saveOrder();
    $render = fn () => view('reports.mission-order', MissionOrderReport::data(MissionOrder::findOrFail($order->id), $this->user))->render();

    expect($render())->toContain('pas encore été contrôlé');

    $this->control($order);
    $this->travel(1)->minutes();
    $this->saveOrder(['notes' => 'Corrigé.'], MissionOrder::findOrFail($order->id));

    expect($render())->toContain('modifié depuis');
});

it('édite le récapitulatif d\'une période en PDF', function () {
    $this->saveOrder();
    $this->saveOrder(['reference' => 'OM-2026-002', 'departure_at' => '2027-01-05 08:00', 'return_at' => '2027-01-09 17:00']);

    $response = $this->get('/api/reports/summary?departure_from=2026-11-01&departure_to=2026-11-30')->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('recapitulatif-om-2026-10-24.pdf')
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(AuditLog::where('action', 'report.summary')->sole()->new_values)
        ->toEqual(['criteria' => 'Départ : du 01/11/2026 au 30/11/2026', 'orders' => 1]);

    $filters = ['departure_from' => '2026-11-01', 'departure_to' => '2026-11-30'];
    $html = view('reports.summary', SummaryReport::data(MissionOrderSearch::query($filters), MissionOrderSearch::describe($filters), $this->user))->render();
    expect($html)
        ->toContain('Départ : du 01/11/2026 au 30/11/2026')
        ->toContain('OM-2026-001')
        ->not->toContain('OM-2026-002')
        ->toContain('503 000 Ar')
        ->toContain('1 250 000 Ar');

    $this->getJson('/api/reports/summary?departure_from=2026-12-01&departure_to=2026-11-01')->assertJsonValidationErrors('departure_to');
});
