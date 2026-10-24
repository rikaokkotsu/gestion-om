<?php

use App\Enums\ConsumptionType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Rate;
use App\Models\RateVersion;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->original = RateVersion::whereNull('effective_from')->firstOrFail();
});

function rateKey(string $category, string $zone, ConsumptionType $type): array
{
    return [
        'category_id' => Category::where('code', $category)->value('id'),
        'zone_id' => Zone::where('code', $zone)->value('id'),
        'consumption_type' => $type->value,
    ];
}

it('fournit le barème du cahier des charges, complet', function () {
    $this->getJson('/api/rate-versions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.effective_from', null)
        ->assertJsonPath('data.0.effective_to', null)
        ->assertJsonPath('data.0.is_locked', false)
        ->assertJsonPath('data.0.missing_count', 0);

    $rates = collect($this->getJson("/api/rate-versions/{$this->original->id}")->assertOk()->json('data.rates'));

    expect($rates)->toHaveCount(70)
        ->and($rates->where('is_applicable', false))->toHaveCount(26)
        ->and($rates->firstWhere(fn ($rate) => $rate === [...rateKey('DG', 'NORD', ConsumptionType::Night), 'amount' => 250000, 'is_applicable' => true]))->not->toBeNull();
});

it('crée une version à partir du barème en vigueur et la journalise', function () {
    $response = $this->postJson('/api/rate-versions', ['label' => 'Barème 2027', 'effective_from' => '2027-01-01'])
        ->assertCreated()
        ->assertJsonPath('data.effective_from', '2027-01-01')
        ->assertJsonPath('data.missing_count', 0);

    $version = RateVersion::findOrFail($response->json('data.id'));
    expect($version->rates()->count())->toBe(70)
        ->and(AuditLog::where('action', 'rate_version.created')->where('auditable_id', $version->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'rate_version.copied')->firstOrFail()->new_values)->toEqual(['source_version_id' => $this->original->id, 'rates' => 70])
        ->and(AuditLog::where('action', 'rate.created')->exists())->toBeFalse();

    $this->getJson('/api/rate-versions')
        ->assertJsonPath('data.0.effective_to', '2026-12-31')
        ->assertJsonPath('data.1.effective_to', null);
});

it('retient la version en vigueur à la date de départ', function () {
    $this->postJson('/api/rate-versions', ['label' => 'Barème 2027', 'effective_from' => '2027-01-01'])->assertCreated();
    $later = RateVersion::whereNotNull('effective_from')->firstOrFail();

    expect(RateVersion::applicableOn(Carbon::parse('2020-05-01'))?->id)->toBe($this->original->id)
        ->and(RateVersion::applicableOn(Carbon::parse('2026-12-31'))?->id)->toBe($this->original->id)
        ->and(RateVersion::applicableOn(Carbon::parse('2027-01-01'))?->id)->toBe($later->id);

    $later->rates()->where(rateKey('R_HC3', 'NORD', ConsumptionType::Night))->update(['amount' => 130000]);
    $simulate = fn (string $date) => $this->postJson('/api/per-diem/simulate', [
        'category_id' => Category::where('code', 'R_HC3')->value('id'),
        'date' => $date,
        'quantities' => [['consumption_type' => 'NUITEE', 'zone_id' => Zone::where('code', 'NORD')->value('id'), 'quantity' => 1]],
    ])->json('data.total');

    expect($simulate('2026-12-31'))->toBe(120000)
        ->and($simulate('2027-03-01'))->toBe(130000);
});

it('refuse une version sans date d\'effet ou à une date déjà prise', function () {
    $this->postJson('/api/rate-versions', ['label' => 'Sans date'])->assertJsonValidationErrors('effective_from');

    $this->postJson('/api/rate-versions', ['label' => 'Barème 2027', 'effective_from' => '2027-01-01'])->assertCreated();
    $this->postJson('/api/rate-versions', ['label' => 'Doublon', 'effective_from' => '2027-01-01'])->assertJsonValidationErrors('effective_from');
});

it('modifie les tarifs et journalise l\'ancienne valeur', function () {
    $this->patchJson("/api/rate-versions/{$this->original->id}", ['rates' => [
        [...rateKey('5A', 'AUTRES', ConsumptionType::Night), 'amount' => 55000, 'is_applicable' => true],
        [...rateKey('5B', 'NORD', ConsumptionType::Breakfast), 'amount' => 6500, 'is_applicable' => false],
        [...rateKey('5B', 'NORD', ConsumptionType::Lunch), 'amount' => 10000, 'is_applicable' => true],
    ]])->assertOk();

    $night = Rate::where(['rate_version_id' => $this->original->id, ...rateKey('5A', 'AUTRES', ConsumptionType::Night)])->firstOrFail();
    $breakfast = Rate::where(['rate_version_id' => $this->original->id, ...rateKey('5B', 'NORD', ConsumptionType::Breakfast)])->firstOrFail();
    $logs = AuditLog::where('action', 'rate.updated')->orderBy('id')->get();

    expect($night->amount)->toBe(55000)
        ->and($breakfast->is_applicable)->toBeFalse()
        ->and($breakfast->amount)->toBeNull()
        // Le déjeuner n'a pas changé : rien n'est journalisé pour lui.
        ->and($logs)->toHaveCount(2)
        ->and($logs[0]->old_values)->toBe(['amount' => 50000])
        ->and($logs[0]->new_values)->toBe(['amount' => 55000]);

    $category = Category::where('code', '5A')->value('label');
    $this->getJson('/api/audit-logs?action=rate.')
        ->assertJsonPath('data.1.subject_label', "{$category} · Autres zones · Nuitée (Barème du cahier des charges v1.0)");
});

it('compte les tarifs manquants d\'une version', function () {
    $this->patchJson("/api/rate-versions/{$this->original->id}", ['rates' => [
        [...rateKey('DIRECTEUR', 'NORD', ConsumptionType::Night), 'amount' => null, 'is_applicable' => true],
    ]])->assertOk()->assertJsonPath('data.missing_count', 1);

    Category::create(['code' => 'STAGIAIRE', 'label' => 'Stagiaire']);

    $this->getJson("/api/rate-versions/{$this->original->id}")->assertJsonPath('data.missing_count', 11);
});

it('fige une version qui a servi à un calcul enregistré', function () {
    $this->original->lock();

    $this->patchJson("/api/rate-versions/{$this->original->id}", ['rates' => [
        [...rateKey('DG', 'NORD', ConsumptionType::Night), 'amount' => 1, 'is_applicable' => true],
    ]])->assertConflict();
    $this->deleteJson("/api/rate-versions/{$this->original->id}")->assertConflict();

    $this->patchJson("/api/rate-versions/{$this->original->id}", ['label' => 'Barème initial'])
        ->assertOk()
        ->assertJsonPath('data.label', 'Barème initial')
        ->assertJsonPath('data.is_locked', true);

    expect(Rate::where(['rate_version_id' => $this->original->id, ...rateKey('DG', 'NORD', ConsumptionType::Night)])->value('amount'))->toBe(250000);
});

it('garde la version d\'origine sans date d\'effet', function () {
    $this->patchJson("/api/rate-versions/{$this->original->id}", ['effective_from' => '2026-01-01'])
        ->assertJsonValidationErrors('effective_from');
    $this->deleteJson("/api/rate-versions/{$this->original->id}")->assertConflict();
});

it('supprime une version qui n\'a pas servi', function () {
    $id = $this->postJson('/api/rate-versions', ['label' => 'Erreur', 'effective_from' => '2027-01-01'])->json('data.id');

    $this->deleteJson("/api/rate-versions/{$id}")->assertNoContent();

    expect(RateVersion::find($id))->toBeNull()
        ->and(Rate::where('rate_version_id', $id)->count())->toBe(0)
        ->and(AuditLog::where('action', 'rate_version.deleted')->firstOrFail()->old_values)->toEqual(['label' => 'Erreur', 'effective_from' => '2027-01-01']);
});
