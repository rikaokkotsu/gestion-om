<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Place;
use App\Models\Service;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  list<list<string|int|null>>  $rows
 */
function xlsxUpload(array $rows, string $name = 'import.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, $name, null, null, true);
}

function csvUpload(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
}

it('importe des lieux avec leur zone et signale les lignes à vérifier', function () {
    Place::create(['name' => 'Toamasina']);

    $report = $this->postJson('/api/imports/places', ['file' => xlsxUpload([
        ['Nom', 'Zone'],
        ['Antsiranana', 'Zone Nord'],
        ['Fianarantsoa', 'Autres'],
        ['toamasina', 'autres zones'],
        ['Lieu sans zone', ''],
        ['Lieu mal classé', 'Sud'],
        ['Antsiranana', 'Nord'],
        ['', ''],
    ])])->assertOk()->json('data');

    expect($report)->toMatchArray(['dry_run' => false, 'created' => 3, 'updated' => 1, 'unchanged' => 0])
        ->and(collect($report['rejected'])->pluck('line')->all())->toBe([6, 7])
        ->and($report['warnings'])->toBe([['line' => 5, 'message' => 'Lieu « Lieu sans zone » créé sans zone.']]);

    expect(Place::where('name', 'Antsiranana')->first()->zone_id)->toBe(Zone::where('code', 'NORD')->value('id'))
        ->and(Place::where('name', 'Toamasina')->first()->zone_id)->toBe(Zone::where('code', 'AUTRES')->value('id'))
        ->and(AuditLog::where('action', 'import.places')->first()->new_values)->toMatchArray(['created' => 3, 'rejected' => 2]);
});

it('n\'écrit rien en simulation', function () {
    $report = $this->postJson('/api/imports/places', [
        'file' => xlsxUpload([['Nom', 'Zone'], ['Antsiranana', 'Nord']]),
        'dry_run' => true,
    ])->assertOk()->json('data');

    expect($report['created'])->toBe(1)
        ->and(Place::count())->toBe(0)
        ->and(AuditLog::where('action', 'like', 'place.%')->count())->toBe(0)
        ->and(AuditLog::where('action', 'import.places')->count())->toBe(0);
});

it('importe des employés, crée les services manquants et trace les affectations', function () {
    Place::create(['name' => 'Antananarivo']);

    $report = $this->postJson('/api/imports/employees', ['file' => xlsxUpload([
        ['Matricule', 'Nom', 'Prénom', 'Poste', 'Service', 'Catégorie', 'Affectation', 'Compte missionné', 'Validateur'],
        [1001, 'Rakoto', 'Jean', 'Superviseur', 'Caisse', 'R HC3', 'Antananarivo', 'CPT-1', 'oui'],
        ['1002', 'Rabe', null, null, 'caisse', 'Cadre HC1', null, null, 'non'],
        ['1003', 'Randria', null, null, null, null, null, null, null],
    ])])->assertOk()->json('data');

    expect($report)->toMatchArray(['created' => 3, 'updated' => 0, 'rejected' => []])
        ->and(collect($report['warnings'])->pluck('message')->all())->toBe([
            'Service « Caisse » ajouté au référentiel.',
            'Employé 1003 enregistré sans catégorie.',
        ]);

    $jean = Employee::where('matricule', '1001')->with('category', 'currentPlace')->firstOrFail();
    expect($jean->category->code)->toBe('R_HC3')
        ->and($jean->currentPlace->name)->toBe('Antananarivo')
        ->and($jean->is_validator)->toBeTrue()
        ->and($jean->account_number)->toBe('CPT-1')
        ->and($jean->assignmentHistories()->sole()->source->value)->toBe('import')
        ->and(Service::count())->toBe(1)
        ->and(Employee::where('matricule', '1002')->first()->service_id)->toBe($jean->service_id);
});

it('met à jour un matricule existant sans effacer ce qui est connu', function () {
    $tana = Place::create(['name' => 'Antananarivo']);
    Place::create(['name' => 'Mahajanga']);
    $employee = Employee::create(['matricule' => '1001', 'last_name' => 'Rakoto', 'position' => 'Caissier', 'current_place_id' => $tana->id]);

    $report = $this->postJson('/api/imports/employees', ['file' => xlsxUpload([
        ['Matricule', 'Nom', 'Poste', 'Affectation'],
        ['1001', 'Rakoto', '', 'Mahajanga'],
    ])])->json('data');

    $employee->refresh();
    expect($report['updated'])->toBe(1)
        ->and($employee->position)->toBe('Caissier')
        ->and($employee->currentPlace()->value('name'))->toBe('Mahajanga')
        ->and($employee->assignmentHistories()->sole()->previous_place_id)->toBe($tana->id);

    $again = $this->postJson('/api/imports/employees', ['file' => xlsxUpload([
        ['Matricule', 'Nom', 'Affectation'],
        ['1001', 'Rakoto', 'Mahajanga'],
    ])])->json('data');
    expect($again['unchanged'])->toBe(1);
});

it('rejette les lignes invalides avec leur motif, sans bloquer les autres', function () {
    $report = $this->postJson('/api/imports/employees', ['file' => xlsxUpload([
        ['Matricule', 'Nom', 'Catégorie', 'Affectation', 'Validateur'],
        ['1001', 'Rakoto', 'Inconnue', 'Nulle part', 'peut-être'],
        ['', 'Sans matricule', '', '', ''],
        ['1002', '', '', '', ''],
        ['1003', 'Rabe', '5A', '', ''],
        ['1003', 'Rabe bis', '5B', '', ''],
    ])])->json('data');

    expect($report['created'])->toBe(1)
        ->and($report['rejected'])->toBe([
            ['line' => 2, 'errors' => [
                'Catégorie inconnue : « Inconnue ».',
                'Lieu d\'affectation inconnu : « Nulle part ». Importez d\'abord les lieux.',
                'Valeur de validateur non reconnue : « peut-être ». Utilisez oui ou non.',
            ]],
            ['line' => 3, 'errors' => ['Le matricule est vide.']],
            ['line' => 4, 'errors' => ['Le nom est vide.']],
            ['line' => 6, 'errors' => ['Matricule 1003 en double dans le fichier (déjà ligne 5).']],
        ])
        ->and(Employee::sole()->category_id)->toBe(Category::where('code', '5A')->value('id'));
});

it('signale les colonnes obligatoires absentes sans rien importer', function () {
    $report = $this->postJson('/api/imports/employees', ['file' => xlsxUpload([
        ['Nom', 'Prénom'],
        ['Rakoto', 'Jean'],
    ])])->json('data');

    expect($report['missing_columns'])->toBe(['matricule'])
        ->and(Employee::count())->toBe(0);
});

it('lit un CSV au format Excel français (points-virgules)', function () {
    $report = $this->postJson('/api/imports/places', [
        'file' => csvUpload("\u{FEFF}Nom;Zone\nAntsiranana;Nord\nToliara;Autres\n"),
    ])->json('data');

    expect($report['created'])->toBe(2)
        ->and(Place::orderBy('name')->pluck('name')->all())->toBe(['Antsiranana', 'Toliara']);
});

it('refuse un fichier d\'un autre format', function () {
    $this->postJson('/api/imports/places', ['file' => UploadedFile::fake()->create('lieux.pdf', 10)])
        ->assertJsonValidationErrors('file');
});

it('fournit un modèle de fichier relisible par l\'import', function (string $type, array $headers) {
    $response = $this->get("/api/imports/{$type}/template")->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    $reader = new Reader;
    $reader->open($path);
    $firstRow = null;
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $firstRow = $row->toArray();
            break 2;
        }
    }
    $reader->close();

    expect($firstRow)->toBe($headers);
})->with([
    'lieux' => ['places', ['Nom', 'Zone']],
    'employés' => ['employees', ['Matricule', 'Nom', 'Prénom', 'Poste', 'Service', 'Catégorie', 'Affectation', 'Compte missionné', 'Validateur']],
]);

it('répond proprement à un fichier Excel corrompu', function () {
    $path = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($path, 'ceci n\'est pas un classeur');

    $this->postJson('/api/imports/places', ['file' => new UploadedFile($path, 'lieux.xlsx', null, null, true)])
        ->assertJsonValidationErrors(['file' => 'Le fichier est illisible ou corrompu.']);
});
