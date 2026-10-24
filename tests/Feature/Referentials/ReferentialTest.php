<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Place;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Zone;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('fournit les valeurs du cahier des charges dès la migration', function () {
    $data = $this->getJson('/api/referentials')->assertOk()->json('data');

    expect(collect($data['zones'])->pluck('code')->all())->toBe(['NORD', 'AUTRES'])
        ->and(collect($data['categories'])->pluck('code')->all())->toBe(['DG', 'DGA', 'R_HC3', 'DIRECTEUR', 'CADRE_HC1', '5A', '5B'])
        ->and($data['mission_objects'])->toHaveCount(5)
        ->and($data['transport_modes'])->toHaveCount(8);

    $paying = TransportMode::where('generates_costs', true)->orderBy('label')->pluck('label')->all();
    expect($paying)->toBe(['Avion', 'Bateau', 'Taxi-brousse', 'Taxi-moto']);
});

it('crée un lieu avec sa zone et le journalise', function () {
    $nord = Zone::where('code', 'NORD')->value('id');

    $this->postJson('/api/referentials/places', ['name' => 'Antsiranana', 'zone_id' => $nord])
        ->assertCreated()
        ->assertJsonPath('data.zone.code', 'NORD');

    $place = Place::where('name', 'Antsiranana')->firstOrFail();
    $log = AuditLog::where('action', 'place.created')->firstOrFail();
    expect($log->auditable_id)->toBe($place->id)
        ->and($log->new_values)->toMatchArray(['name' => 'Antsiranana', 'zone_id' => $nord]);
});

it('accepte un lieu sans zone', function () {
    $this->postJson('/api/referentials/places', ['name' => 'Lieu à classer'])
        ->assertCreated()
        ->assertJsonPath('data.zone', null);
});

it('refuse un doublon de nom de lieu', function () {
    Place::create(['name' => 'Toamasina']);

    $this->postJson('/api/referentials/places', ['name' => 'Toamasina'])
        ->assertJsonValidationErrors('name');
});

it('modifie un moyen de transport et journalise l\'avant et l\'après', function () {
    $mode = TransportMode::where('label', 'Bateau')->firstOrFail();

    $this->patchJson("/api/referentials/transport-modes/{$mode->id}", ['generates_costs' => false])
        ->assertOk()
        ->assertJsonPath('data.generates_costs', false);

    $log = AuditLog::where('action', 'transport_mode.updated')->firstOrFail();
    expect($log->old_values)->toBe(['generates_costs' => true])
        ->and($log->new_values)->toBe(['generates_costs' => false]);
});

it('désactive un objet de mission sans le supprimer', function () {
    $id = $this->getJson('/api/referentials/mission-objects')->json('data.0.id');

    $this->patchJson("/api/referentials/mission-objects/{$id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->getJson('/api/referentials/mission-objects')->assertJsonCount(5, 'data');
});

it('crée une catégorie mais interdit de changer son code ensuite', function () {
    $this->postJson('/api/referentials/categories', ['code' => '4A', 'label' => '4A'])->assertCreated();
    $this->postJson('/api/referentials/categories', ['code' => 'r hc3', 'label' => 'X'])->assertJsonValidationErrors('code');

    $category = Category::where('code', '4A')->firstOrFail();
    $this->patchJson("/api/referentials/categories/{$category->id}", ['code' => '4B'])->assertJsonValidationErrors('code');
    $this->patchJson("/api/referentials/categories/{$category->id}", ['label' => 'Catégorie 4A'])->assertOk();
});

it('renvoie 404 pour un référentiel inconnu', function () {
    $this->getJson('/api/referentials/zones')->assertNotFound();
    $this->getJson('/api/referentials/inconnu')->assertNotFound();
});
