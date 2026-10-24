<?php

use App\Enums\ConsumptionType;
use App\Models\Category;
use App\Models\Rate;
use App\Models\RateVersion;
use App\Models\User;
use App\Models\Zone;
use App\PerDiem\Consumption;
use App\PerDiem\PerDiemCalculator;
use App\PerDiem\PerDiemLine;

function category(string $code): Category
{
    return Category::where('code', $code)->firstOrFail();
}

function zoneId(string $code): int
{
    return (int) Zone::where('code', $code)->value('id');
}

/**
 * Exemple du cahier des charges : missionnaire R HC3, 3 jours en zone Nord
 * puis 2 jours dans les autres zones.
 *
 * @return list<Consumption>
 */
function referenceCase(): array
{
    $nord = zoneId(Zone::NORD);
    $autres = zoneId(Zone::AUTRES);

    return [
        new Consumption(ConsumptionType::Breakfast, $nord, 3),
        new Consumption(ConsumptionType::Lunch, $nord, 2),
        new Consumption(ConsumptionType::Dinner, $nord, 2),
        new Consumption(ConsumptionType::Night, $nord, 2),
        new Consumption(ConsumptionType::Breakfast, $autres, 2),
        new Consumption(ConsumptionType::Lunch, $autres, 2),
        new Consumption(ConsumptionType::Dinner, $autres, 2),
        new Consumption(ConsumptionType::Night, $autres, 2),
    ];
}

function originalVersion(): RateVersion
{
    return RateVersion::whereNull('effective_from')->firstOrFail();
}

it('retrouve les 503 000 Ar de l\'exemple du cahier des charges', function () {
    $result = app(PerDiemCalculator::class)->calculate(category('R_HC3'), referenceCase(), originalVersion());

    expect($result->complete)->toBeTrue()
        ->and($result->issues)->toBe([])
        ->and($result->zoneTotals)->toBe(['NORD' => 323000, 'AUTRES' => 180000])
        ->and($result->total)->toBe(503000)
        ->and($result->lines)->toHaveCount(8)
        ->and($result->lines[0]->rate)->toBe(9000)
        ->and($result->lines[0]->amount)->toBe(27000);
});

it('calcule le forfait repas des catégories qui n\'ont pas de repas détaillés', function () {
    $nord = zoneId(Zone::NORD);

    $result = app(PerDiemCalculator::class)->calculate(category('DG'), [
        new Consumption(ConsumptionType::MealAllowance, $nord, 3),
        new Consumption(ConsumptionType::Night, $nord, 2),
    ], originalVersion());

    expect($result->complete)->toBeTrue()
        ->and($result->total)->toBe(3 * 78000 + 2 * 250000);
});

it('ne compte jamais un tarif manquant comme nul', function () {
    $nord = zoneId(Zone::NORD);
    Rate::where('rate_version_id', originalVersion()->id)
        ->where('category_id', category('R_HC3')->id)
        ->where('zone_id', $nord)
        ->where('consumption_type', ConsumptionType::Dinner->value)
        ->update(['amount' => null]);

    $result = app(PerDiemCalculator::class)->calculate(category('R_HC3'), referenceCase(), originalVersion());
    $dinner = collect($result->lines)->first(fn (PerDiemLine $line) => $line->type === ConsumptionType::Dinner && $line->zoneId === $nord);

    expect($result->complete)->toBeFalse()
        ->and($dinner->status)->toBe(PerDiemLine::MISSING_RATE)
        ->and($dinner->rate)->toBeNull()
        ->and($dinner->amount)->toBeNull()
        ->and($result->total)->toBe(503000 - 2 * 15000)
        ->and($result->issues)->toBe(['Tarif non renseigné : dîner, R HC3, Zone Nord.']);
});

it('accepte un tarif explicitement nul', function () {
    $nord = zoneId(Zone::NORD);
    Rate::where('rate_version_id', originalVersion()->id)
        ->where('category_id', category('5A')->id)
        ->where('zone_id', $nord)
        ->where('consumption_type', ConsumptionType::Breakfast->value)
        ->update(['amount' => 0]);

    $result = app(PerDiemCalculator::class)->calculate(category('5A'), [
        new Consumption(ConsumptionType::Breakfast, $nord, 2),
    ], originalVersion());

    expect($result->complete)->toBeTrue()
        ->and($result->total)->toBe(0);
});

it('signale une consommation sans objet pour la catégorie', function () {
    $result = app(PerDiemCalculator::class)->calculate(category('DG'), [
        new Consumption(ConsumptionType::Breakfast, zoneId(Zone::AUTRES), 1),
    ], originalVersion());

    expect($result->complete)->toBeFalse()
        ->and($result->lines[0]->status)->toBe(PerDiemLine::NOT_APPLICABLE)
        ->and($result->lines[0]->amount)->toBeNull()
        ->and($result->issues[0])->toBe('Petit-déjeuner sans objet pour la catégorie Directeur Général (Autres zones).');
});

it('signale une quantité négative sans la soustraire', function () {
    $result = app(PerDiemCalculator::class)->calculate(category('R_HC3'), [
        new Consumption(ConsumptionType::Night, zoneId(Zone::NORD), -1),
        new Consumption(ConsumptionType::Night, zoneId(Zone::AUTRES), 1),
    ], originalVersion());

    expect($result->complete)->toBeFalse()
        ->and($result->lines[0]->status)->toBe(PerDiemLine::INVALID_QUANTITY)
        ->and($result->total)->toBe(65000);
});

it('ignore les quantités nulles', function () {
    $result = app(PerDiemCalculator::class)->calculate(category('R_HC3'), [
        new Consumption(ConsumptionType::Breakfast, zoneId(Zone::NORD), 0),
    ], originalVersion());

    expect($result->lines)->toBe([])
        ->and($result->complete)->toBeTrue()
        ->and($result->total)->toBe(0);
});

it('signale une catégorie manquante au lieu de calculer', function () {
    $result = app(PerDiemCalculator::class)->calculate(null, referenceCase(), originalVersion());

    expect($result->complete)->toBeFalse()
        ->and($result->total)->toBe(0)
        ->and($result->issues)->toBe(['Catégorie du missionnaire manquante : aucun tarif ne peut être retenu.'])
        ->and(collect($result->lines)->pluck('status')->unique()->all())->toBe([PerDiemLine::MISSING_RATE]);
});

it('signale l\'absence de barème applicable', function () {
    $result = app(PerDiemCalculator::class)->calculate(category('R_HC3'), referenceCase(), null);

    expect($result->complete)->toBeFalse()
        ->and($result->issues)->toBe(['Aucune version de barème ne s\'applique à cette date.']);
});

it('signale les tarifs absents d\'une catégorie créée après le barème', function () {
    $category = Category::create(['code' => 'STAGIAIRE', 'label' => 'Stagiaire']);

    $result = app(PerDiemCalculator::class)->calculate($category, [
        new Consumption(ConsumptionType::Night, zoneId(Zone::NORD), 1),
    ], originalVersion());

    expect($result->complete)->toBeFalse()
        ->and($result->lines[0]->status)->toBe(PerDiemLine::MISSING_RATE)
        ->and($result->issues)->toBe(['Tarif non renseigné : nuitée, Stagiaire, Zone Nord.']);
});

it('simule le cas de référence par l\'API', function () {
    $this->actingAs(User::factory()->create());
    $nord = zoneId(Zone::NORD);
    $autres = zoneId(Zone::AUTRES);

    $this->postJson('/api/per-diem/simulate', [
        'category_id' => category('R_HC3')->id,
        'date' => '2026-10-24',
        'quantities' => collect(referenceCase())->map(fn (Consumption $line) => [
            'consumption_type' => $line->type->value,
            'zone_id' => $line->zoneId,
            'quantity' => $line->quantity,
        ])->all(),
    ])
        ->assertOk()
        ->assertJsonPath('data.total', 503000)
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath('data.zone_totals.NORD', 323000)
        ->assertJsonPath('data.zone_totals.AUTRES', 180000)
        ->assertJsonPath('data.rate_version.label', 'Barème du cahier des charges v1.0');

    $this->postJson('/api/per-diem/simulate', [
        'category_id' => category('R_HC3')->id,
        'quantities' => [['consumption_type' => 'NUITEE', 'zone_id' => $nord, 'quantity' => -1], ['consumption_type' => 'AUTRE', 'zone_id' => $autres, 'quantity' => 1]],
    ])->assertJsonValidationErrors(['quantities.0.quantity', 'quantities.1.consumption_type']);
});
