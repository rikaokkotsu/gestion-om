<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConsumptionType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\RateVersion;
use App\PerDiem\Consumption;
use App\PerDiem\PerDiemCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PerDiemController extends Controller
{
    /**
     * Calcul à blanc, sans rien enregistrer : vérifie un barème ou un cas.
     */
    public function simulate(Request $request, PerDiemCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'date' => ['nullable', 'date'],
            'rate_version_id' => ['nullable', 'integer', 'exists:rate_versions,id'],
            'quantities' => ['required', 'array'],
            'quantities.*.consumption_type' => ['required', Rule::enum(ConsumptionType::class)],
            'quantities.*.zone_id' => ['required', 'integer', 'exists:zones,id'],
            'quantities.*.quantity' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $version = isset($data['rate_version_id'])
            ? RateVersion::query()->find($data['rate_version_id'])
            : RateVersion::applicableOn(isset($data['date']) ? Carbon::parse($data['date']) : today());

        $consumptions = array_map(fn (array $line) => new Consumption(
            ConsumptionType::from($line['consumption_type']),
            (int) $line['zone_id'],
            (int) $line['quantity'],
        ), $data['quantities']);

        $result = $calculator->calculate(Category::query()->find($data['category_id']), $consumptions, $version);

        return response()->json(['data' => $result->toArray()]);
    }
}
