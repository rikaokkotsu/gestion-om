<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReportAnomaly;
use App\Actions\TransitionAnomaly;
use App\Actions\UpdateAnomaly;
use App\Control\RuleRegistry;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyEventType;
use App\Enums\AnomalyNature;
use App\Enums\AnomalyPriority;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnomalyResource;
use App\Models\Anomaly;
use App\Models\MissionOrder;
use App\Models\User;
use App\Search\AnomalySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AnomalyController extends Controller
{
    /**
     * Ordre de lecture : à traiter d'abord, puis par priorité.
     */
    private const ORDER_BY_STATE = "case state when 'PROPOSED' then 0 when 'CONFIRMED' then 1 when 'TRANSMITTED' then 2 else 3 end";

    private const ORDER_BY_PRIORITY = "case priority when 'HIGH' then 0 when 'MEDIUM' then 1 else 2 end";

    private const ATTRIBUTES = [
        'mission_order_id' => 'OM',
        'member_id' => 'missionnaire',
        'category' => 'catégorie',
        'nature' => 'nature',
        'priority' => 'priorité',
        'rule_code' => 'règle',
        'description' => 'description',
        'comment' => 'commentaire',
        'state' => 'état',
    ];

    /**
     * Liste filtrable (§ 13.4) ; les anomalies des OM supprimés n'y figurent pas.
     */
    public function index(Request $request, AnomalySearch $search): AnonymousResourceCollection
    {
        $anomalies = AnomalySearch::query($search->validate($request))
            ->with(AnomalyResource::RELATIONS)
            ->paginate(25)
            ->withQueryString();

        return AnomalyResource::collection($anomalies);
    }

    /**
     * Anomalies d'un OM, à traiter d'abord.
     */
    public function forOrder(MissionOrder $missionOrder): AnonymousResourceCollection
    {
        $anomalies = $missionOrder->anomalies()
            ->with(AnomalyResource::RELATIONS)
            ->orderByRaw(self::ORDER_BY_STATE)
            ->orderByRaw(self::ORDER_BY_PRIORITY)
            ->orderBy('id')
            ->get();

        return AnomalyResource::collection($anomalies);
    }

    public function show(Anomaly $anomaly): AnomalyResource
    {
        return new AnomalyResource($anomaly->load([...AnomalyResource::RELATIONS, 'events.user']));
    }

    public function store(Request $request, ReportAnomaly $report, RuleRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'mission_order_id' => ['required', 'integer', Rule::exists('mission_orders', 'id')->whereNull('deleted_at')],
            'member_id' => ['nullable', 'integer', Rule::exists('mission_order_employees', 'id')->where('mission_order_id', $request->integer('mission_order_id'))],
            'category' => ['required', Rule::enum(AnomalyCategory::class)],
            'nature' => ['nullable', Rule::enum(AnomalyNature::class)],
            'priority' => ['nullable', Rule::enum(AnomalyPriority::class)],
            'rule_code' => ['nullable', Rule::in(array_keys($registry->all()))],
            'description' => ['required', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], attributes: self::ATTRIBUTES);

        $order = MissionOrder::query()->findOrFail($data['mission_order_id']);
        $anomaly = $report($order, $data, $this->actor($request));

        return $this->show($anomaly)->response()->setStatusCode(201);
    }

    public function update(Request $request, Anomaly $anomaly, UpdateAnomaly $update): AnomalyResource
    {
        $manual = $anomaly->source === AnomalySource::Manual;
        $data = $request->validate([
            'priority' => ['sometimes', Rule::enum(AnomalyPriority::class)],
            'category' => [$manual ? 'sometimes' : 'prohibited', Rule::enum(AnomalyCategory::class)],
            'description' => [$manual ? 'sometimes' : 'prohibited', 'string', 'max:2000'],
            'member_id' => [
                $manual ? 'sometimes' : 'prohibited', 'nullable', 'integer',
                Rule::exists('mission_order_employees', 'id')->where('mission_order_id', $anomaly->mission_order_id),
            ],
        ], [
            'prohibited' => 'Seule la priorité d\'une anomalie détectée par le contrôle peut être modifiée ; ajoutez un commentaire pour la nuancer.',
        ], self::ATTRIBUTES);

        return $this->show($update($anomaly, $data, $this->actor($request)));
    }

    public function transition(Request $request, Anomaly $anomaly, TransitionAnomaly $transition): AnomalyResource
    {
        $data = $request->validate([
            'state' => ['required', Rule::enum(AnomalyState::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], attributes: self::ATTRIBUTES);

        return $this->show($transition($anomaly, AnomalyState::from($data['state']), $data['comment'] ?? null, $this->actor($request)));
    }

    public function comment(Request $request, Anomaly $anomaly): AnomalyResource
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']], attributes: self::ATTRIBUTES);

        $anomaly->events()->create([
            'user_id' => $this->actor($request)?->id,
            'type' => AnomalyEventType::Commented,
            'comment' => trim($data['comment']),
        ]);

        return $this->show($anomaly);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
