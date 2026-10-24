<?php

namespace App\Http\Controllers\Api;

use App\Control\ControlEngine;
use App\Control\RuleRegistry;
use App\Enums\AdministrativeStatus;
use App\Enums\ControlTrigger;
use App\Enums\MissionOrderType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ControlRunResource;
use App\Jobs\RunControlBatch;
use App\Models\Anomaly;
use App\Models\ControlRuleSetting;
use App\Models\ControlRun;
use App\Models\MissionOrder;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ControlController extends Controller
{
    /**
     * Contrôle immédiat d'un OM, à la demande du contrôleur.
     */
    public function control(Request $request, MissionOrder $missionOrder, ControlEngine $engine): JsonResponse
    {
        $run = $engine->controlOrder($missionOrder, $this->actor($request));

        return (new ControlRunResource($run->load(ControlRunResource::RELATIONS)))->response()->setStatusCode(200);
    }

    /**
     * Catalogue des règles, avec leur activation et le nombre d'anomalies à traiter.
     */
    public function rules(RuleRegistry $registry): JsonResponse
    {
        $disabled = $registry->disabledCodes();
        $open = Anomaly::query()->onActiveOrders()->open()->whereNotNull('rule_code')
            ->groupBy('rule_code')->selectRaw('rule_code, count(*) as total')->pluck('total', 'rule_code');

        $rules = array_values(array_map(function ($rule) use ($disabled, $open) {
            $definition = $rule->definition();

            return [
                'code' => $definition->code,
                'category' => $definition->category->value,
                'category_label' => $definition->category->label(),
                'nature' => $definition->nature->value,
                'nature_label' => $definition->nature->label(),
                'priority' => $definition->nature->defaultPriority()->value,
                'priority_label' => $definition->nature->defaultPriority()->label(),
                'label' => $definition->label,
                'explanation' => $definition->explanation,
                'is_enabled' => ! in_array($definition->code, $disabled, true),
                'open_anomalies_count' => (int) ($open[$definition->code] ?? 0),
            ];
        }, $registry->all()));

        return response()->json(['data' => $rules]);
    }

    /**
     * Active ou désactive une règle ; ses anomalies existantes sont conservées.
     */
    public function updateRule(Request $request, string $code, RuleRegistry $registry): JsonResponse
    {
        abort_if($registry->find($code) === null, 404);
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $actor = $this->actor($request);

        $setting = ControlRuleSetting::query()->firstOrNew(['code' => $code]);
        $was = $setting->exists ? $setting->is_enabled : true;
        $setting->fill(['is_enabled' => (bool) $data['is_enabled'], 'updated_by_id' => $actor?->id])->save();

        if ($was !== $setting->is_enabled) {
            Audit::record('control_rule.updated', $setting, ['is_enabled' => $was], ['is_enabled' => $setting->is_enabled], $actor);
        }

        return response()->json(['data' => ['code' => $code, 'is_enabled' => $setting->is_enabled]]);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['trigger' => ['nullable', Rule::enum(ControlTrigger::class)]]);

        $runs = ControlRun::query()
            ->with(ControlRunResource::RELATIONS)
            ->when($filters['trigger'] ?? null, fn ($query, string $trigger) => $query->where('trigger', $trigger))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return ControlRunResource::collection($runs);
    }

    /**
     * Contrôle par lot, placé dans la file d'attente.
     */
    public function storeRun(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'departure_from' => ['nullable', 'date'],
            'departure_to' => ['nullable', 'date', 'after_or_equal:departure_from'],
            'type' => ['nullable', Rule::enum(MissionOrderType::class)],
            'administrative_status' => ['nullable', Rule::enum(AdministrativeStatus::class)],
            'scope' => ['required', Rule::in(['all', 'pending'])],
        ], attributes: [
            'departure_from' => 'début de la période',
            'departure_to' => 'fin de la période',
            'scope' => 'OM à contrôler',
        ]);
        $actor = $this->actor($request);

        $run = ControlRun::create([
            'trigger' => ControlTrigger::Batch,
            'filters' => array_filter($filters, fn ($value) => $value !== null),
            'launched_by_id' => $actor?->id,
        ]);
        Audit::record('control.batch', $run, [], $run->filters ?? [], $actor);

        RunControlBatch::dispatch($run->id);

        return (new ControlRunResource($run->refresh()->load(ControlRunResource::RELATIONS)))->response()->setStatusCode(202);
    }

    public function showRun(ControlRun $controlRun): ControlRunResource
    {
        return new ControlRunResource($controlRun->load(ControlRunResource::RELATIONS));
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
