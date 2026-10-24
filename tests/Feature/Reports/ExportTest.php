<?php

use App\Models\Anomaly;
use App\Models\AuditLog;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
    $this->travelTo('2026-10-24 14:05');
});

/**
 * Feuilles du classeur téléchargé : nom => lignes (valeurs des cellules).
 *
 * @return array<string, list<list<mixed>>>
 */
function workbook(TestResponse $response): array
{
    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    return $sheets;
}

it('exporte les OM retenus par la recherche, une feuille par sujet', function () {
    $order = $this->saveOrder(['notes' => '=HYPERLINK("http://exemple.test")', 'declared_duration' => 4]);
    $this->control($order);
    $this->saveOrder(['reference' => 'OM-2026-002', 'type' => 'AFFECTATION']);

    $response = $this->get('/api/exports/mission-orders?type=MISSION')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('om-2026-10-24.xlsx');
    $sheets = workbook($response);
    expect(array_keys($sheets))->toBe(['OM', 'Missionnaires', 'Frais', 'Transports', 'Anomalies', 'Critères']);

    [$header, $row] = $sheets['OM'];
    $values = array_combine($header, $row);
    expect(count($sheets['OM']))->toBe(2)
        ->and($values['Référence'])->toBe('OM-2026-001')
        ->and($values['Départ'])->toBeInstanceOf(DateTimeInterface::class)
        ->and($values['Départ']->format('d/m/Y H:i'))->toBe('03/11/2026 08:00')
        ->and($values['Durée déclarée (jours)'])->toBe(4)
        ->and($values['Durée selon les dates (jours)'])->toBe(5)
        ->and($values['Per diem déclaré (Ar)'])->toBe(503000)
        ->and($values['Per diem calculé (Ar)'])->toBe(503000)
        ->and($values['Calcul du per diem'])->toBe('Complet')
        ->and($values['Frais supplémentaires (Ar)'])->toBe(1250000)
        ->and($values['Validateur'])->toBe('Rabe Hery (M002)')
        ->and($values['Anomalies à traiter'])->toBe(1)
        // Un texte commençant par « = » reste un texte, jamais une formule.
        ->and($values['Observations'])->toBe('=HYPERLINK("http://exemple.test")');

    $member = array_combine($sheets['Missionnaires'][0], $sheets['Missionnaires'][1]);
    expect($member)->toMatchArray([
        'Matricule' => 'M001',
        'Catégorie sur l\'OM' => 'R_HC3',
        'Per diem déclaré (Ar)' => 503000,
        'Per diem calculé (Ar)' => 503000,
        'Écart déclaré − calculé (Ar)' => 0,
    ]);
    expect(array_column(array_slice($sheets['Frais'], 1), 3))->toBe([1200000, 50000])
        ->and(count($sheets['Transports']))->toBe(3)
        ->and($sheets['Anomalies'][1][3])->toBe('DAT-02')
        ->and($sheets['Critères'])->toContain(['Export des OM du 24/10/2026 à 14:05 par '.$this->user->name], ['  Type : Mission'], ['OM exportés : 1']);

    $log = AuditLog::where('action', 'export.mission_orders')->sole();
    expect($log->new_values)->toEqual(['criteria' => 'Type : Mission', 'orders' => 1])
        ->and($log->user_id)->toBe($this->user->id);

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->not->toContain('<f>');
});

it('laisse vide le per diem calculé d\'un calcul incomplet, sans le compter comme nul', function () {
    $this->saveOrder(['employees' => [$this->member($this->rakoto), $this->member($this->rabe, ['category_id' => null])]]);

    $sheets = workbook($this->get('/api/exports/mission-orders')->assertOk());

    $order = array_combine($sheets['OM'][0], $sheets['OM'][1]);
    $incomplete = array_combine($sheets['Missionnaires'][0], $sheets['Missionnaires'][2]);
    expect($order['Per diem calculé (Ar)'])->toBe('')
        ->and($order['Calcul du per diem'])->toBe('Incomplet pour 1 missionnaire')
        ->and($incomplete['Per diem calculé (Ar)'])->toBe('')
        ->and($incomplete['Écart déclaré − calculé (Ar)'])->toBe('')
        ->and($incomplete['Calcul'])->toStartWith('Incomplet');
});

it('exporte la liste des anomalies avec les filtres de l\'écran', function () {
    $order = $this->saveOrder(['declared_duration' => 4, 'assignment_allowance' => 300000]);
    $this->control($order);
    $dismissed = Anomaly::where('rule_code', 'AFF-01')->value('id');
    $this->postJson("/api/anomalies/{$dismissed}/transition", ['state' => 'DISMISSED', 'comment' => 'Convention.'])->assertOk();

    $response = $this->get('/api/exports/anomalies?state=open')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('anomalies-2026-10-24.xlsx');
    $sheets = workbook($response);
    $row = array_combine($sheets['Anomalies'][0], $sheets['Anomalies'][1]);
    expect(count($sheets['Anomalies']))->toBe(2)
        ->and($row)->toMatchArray([
            'Référence de l\'OM' => 'OM-2026-001',
            'Règle' => 'DAT-02',
            'État' => 'Proposée',
            'Origine' => 'Contrôle automatique',
            'Missionnaire' => 'L\'OM',
        ])
        ->and($sheets['Critères'])->toContain(['  État : à traiter'], ['Anomalies exportées : 1'])
        ->and(AuditLog::where('action', 'export.anomalies')->sole()->new_values)->toEqual(['criteria' => 'État : à traiter', 'anomalies' => 1]);

    $this->getJson('/api/exports/anomalies?from=2026-10-24&to=2026-10-01')->assertJsonValidationErrors('to');
});

it('réserve les exports aux contrôleurs connectés', function () {
    auth()->logout();

    $this->getJson('/api/exports/mission-orders')->assertUnauthorized();
    $this->getJson('/api/exports/anomalies')->assertUnauthorized();
    $this->getJson('/api/dashboard')->assertUnauthorized();
});
