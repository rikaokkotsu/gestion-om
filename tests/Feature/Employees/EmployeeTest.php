<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Place;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->category = Category::where('code', 'R_HC3')->firstOrFail();
    $this->tana = Place::create(['name' => 'Antananarivo']);
    $this->majunga = Place::create(['name' => 'Mahajanga']);
});

function employeePayload(array $overrides = []): array
{
    return [
        'matricule' => 'M001',
        'last_name' => 'Rakoto',
        'first_name' => 'Jean',
        'position' => 'Superviseur',
        ...$overrides,
    ];
}

it('crée une fiche employé et trace sa première affectation', function () {
    $this->postJson('/api/employees', employeePayload([
        'category_id' => $this->category->id,
        'current_place_id' => $this->tana->id,
        'assignment_date' => '2026-01-15',
        'is_validator' => true,
    ]))->assertCreated()
        ->assertJsonPath('data.category.code', 'R_HC3')
        ->assertJsonPath('data.current_place.name', 'Antananarivo')
        ->assertJsonPath('data.is_validator', true);

    $employee = Employee::where('matricule', 'M001')->firstOrFail();
    $history = $employee->assignmentHistories()->sole();
    expect($history->previous_place_id)->toBeNull()
        ->and($history->new_place_id)->toBe($this->tana->id)
        ->and($history->effective_date->toDateString())->toBe('2026-01-15')
        ->and($history->source->value)->toBe('manual')
        ->and($history->recorded_by_id)->toBe($this->user->id)
        ->and(AuditLog::where('action', 'employee.created')->where('auditable_id', $employee->id)->exists())->toBeTrue();
});

it('accepte une fiche incomplète : seuls le matricule et le nom sont exigés', function () {
    $this->postJson('/api/employees', ['matricule' => 'M002', 'last_name' => 'Rabe'])->assertCreated();
    $this->postJson('/api/employees', ['last_name' => 'Rabe'])->assertJsonValidationErrors('matricule');
});

it('refuse un matricule déjà utilisé', function () {
    Employee::create(['matricule' => 'M001', 'last_name' => 'Rakoto']);

    $this->postJson('/api/employees', employeePayload())->assertJsonValidationErrors('matricule');
});

it('ajoute une ligne d\'historique à chaque changement d\'affectation, et seulement alors', function () {
    $id = $this->postJson('/api/employees', employeePayload(['current_place_id' => $this->tana->id, 'assignment_date' => '2026-01-15']))->json('data.id');

    $this->patchJson("/api/employees/{$id}", ['position' => 'Chef d\'agence'])->assertOk();
    $this->patchJson("/api/employees/{$id}", ['current_place_id' => $this->majunga->id, 'assignment_date' => '2026-06-01'])
        ->assertOk()
        ->assertJsonPath('data.current_place.name', 'Mahajanga')
        ->assertJsonPath('data.assignment_histories.0.previous_place.name', 'Antananarivo')
        ->assertJsonPath('data.assignment_histories.0.new_place.name', 'Mahajanga')
        ->assertJsonPath('data.assignment_histories.0.effective_date', '2026-06-01')
        ->assertJsonCount(2, 'data.assignment_histories');
});

it('recherche par matricule, nom, prénom et filtre par service, catégorie, affectation et validateur', function () {
    $service = Service::create(['name' => 'Caisse']);
    Employee::create(['matricule' => 'A100', 'last_name' => 'Rakoto', 'first_name' => 'Jean', 'service_id' => $service->id, 'is_validator' => true]);
    Employee::create(['matricule' => 'B200', 'last_name' => 'Rabe', 'first_name' => 'Hery', 'category_id' => $this->category->id, 'current_place_id' => $this->tana->id]);
    Employee::create(['matricule' => 'C300', 'last_name' => 'Randria', 'is_active' => false]);

    $matricules = fn (string $query) => collect($this->getJson("/api/employees?{$query}")->assertOk()->json('data'))->pluck('matricule')->all();

    expect($matricules('search=a100'))->toBe(['A100'])
        ->and($matricules('search=jean rakoto'))->toBe(['A100'])
        ->and($matricules('search=hery'))->toBe(['B200'])
        ->and($matricules("service_id={$service->id}"))->toBe(['A100'])
        ->and($matricules("category_id={$this->category->id}"))->toBe(['B200'])
        ->and($matricules("current_place_id={$this->tana->id}"))->toBe(['B200'])
        ->and($matricules('is_validator=1'))->toBe(['A100'])
        ->and($matricules('search=r'))->toBe(['B200', 'A100'])
        ->and($matricules('status=inactive'))->toBe(['C300'])
        ->and($matricules('status=all'))->toHaveCount(3);
});

it('traite les caractères spéciaux de la recherche comme du texte', function () {
    Employee::create(['matricule' => 'A100', 'last_name' => 'Rakoto']);

    expect($this->getJson('/api/employees?search=%25')->json('data'))->toBe([]);
});

it('laisse inconnue la date d\'une affectation initiale et date du jour un changement sans date', function () {
    $this->travelTo('2026-10-25 09:00');
    $id = $this->postJson('/api/employees', employeePayload(['current_place_id' => $this->tana->id]))->json('data.id');

    $this->patchJson("/api/employees/{$id}", ['current_place_id' => $this->majunga->id])
        ->assertJsonPath('data.assignment_histories.0.effective_date', '2026-10-25')
        ->assertJsonPath('data.assignment_histories.1.effective_date', null);
});
