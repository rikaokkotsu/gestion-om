<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateRateVersion;
use App\Enums\ConsumptionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\RateVersionResource;
use App\Models\Rate;
use App\Models\RateVersion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RateVersionController extends Controller
{
    private const LOCKED = 'Cette version a déjà servi à un calcul enregistré : ses tarifs et sa date d\'effet ne se modifient plus. Créez une nouvelle version.';

    public function index(): JsonResponse
    {
        $versions = RateVersion::query()->chronological()->withFilledCount()->get();
        $expected = RateVersion::expectedRateCount();

        $data = $versions->values()->map(fn (RateVersion $version, int $index) => (new RateVersionResource($version))
            ->withContext($versions->get($index + 1), $expected)
            ->resolve());

        return response()->json(['data' => $data]);
    }

    public function show(RateVersion $rateVersion): RateVersionResource
    {
        $version = RateVersion::query()->withFilledCount()->with('rates')->findOrFail($rateVersion->id);

        return (new RateVersionResource($version))->withContext($version->next(), RateVersion::expectedRateCount());
    }

    public function store(Request $request, CreateRateVersion $create): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date', Rule::unique('rate_versions', 'effective_from')],
            'copy_from_id' => ['nullable', 'integer', 'exists:rate_versions,id'],
        ]);

        $user = $request->user();
        $version = $create(
            $data['label'],
            Carbon::parse($data['effective_from']),
            isset($data['copy_from_id']) ? RateVersion::query()->find($data['copy_from_id']) : null,
            $user instanceof User ? $user : null,
        );

        return $this->show($version)->response()->setStatusCode(201);
    }

    public function update(Request $request, RateVersion $rateVersion): RateVersionResource
    {
        if ($rateVersion->isLocked() && ($request->has('effective_from') || $request->has('rates'))) {
            abort(409, self::LOCKED);
        }

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            // La version d'origine couvre toute date antérieure aux suivantes : elle reste sans date.
            'effective_from' => $rateVersion->effective_from === null
                ? ['prohibited']
                : ['sometimes', 'required', 'date', Rule::unique('rate_versions', 'effective_from')->ignore($rateVersion->id)],
            'rates' => ['sometimes', 'array'],
            'rates.*.category_id' => ['required', 'integer', 'exists:categories,id'],
            'rates.*.zone_id' => ['required', 'integer', 'exists:zones,id'],
            'rates.*.consumption_type' => ['required', Rule::enum(ConsumptionType::class)],
            'rates.*.is_applicable' => ['required', 'boolean'],
            'rates.*.amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        DB::transaction(function () use ($rateVersion, $data) {
            $rateVersion->update(Arr::only($data, ['label', 'effective_from']));

            foreach ($data['rates'] ?? [] as $values) {
                $applicable = (bool) $values['is_applicable'];
                // Chaque tarif modifié est journalisé avec son ancienne valeur.
                Rate::query()->updateOrCreate([
                    'rate_version_id' => $rateVersion->id,
                    'category_id' => $values['category_id'],
                    'zone_id' => $values['zone_id'],
                    'consumption_type' => $values['consumption_type'],
                ], [
                    'is_applicable' => $applicable,
                    // Une consommation sans objet n'a pas de tarif.
                    'amount' => $applicable ? ($values['amount'] ?? null) : null,
                ]);
            }
        });

        return $this->show($rateVersion);
    }

    public function destroy(Request $request, RateVersion $rateVersion): JsonResponse
    {
        if ($rateVersion->isLocked()) {
            abort(409, self::LOCKED);
        }
        if ($rateVersion->effective_from === null) {
            abort(409, 'La version d\'origine ne peut pas être supprimée.');
        }

        $user = $request->user();
        Audit::record('rate_version.deleted', $rateVersion, [
            'label' => $rateVersion->label,
            'effective_from' => $rateVersion->effective_from->toDateString(),
        ], [], $user instanceof User ? $user : null);
        $rateVersion->delete();

        return response()->json(null, 204);
    }
}
