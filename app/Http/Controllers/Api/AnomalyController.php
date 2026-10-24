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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
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
    public function index(Request $request, RuleRegistry $registry): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'all', ...array_column(AnomalyState::cases(), 'value')])],
            'category' => ['nullable', Rule::enum(AnomalyCategory::class)],
            'source' => ['nullable', Rule::enum(AnomalySource::class)],
            'priority' => ['nullable', Rule::enum(AnomalyPriority::class)],
            'rule_code' => ['nullable', Rule::in(array_keys($registry->all()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'mission_order_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'control_run_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
        ], attributes: ['from' => 'date de début', 'to' => 'date de fin']);

        $anomalies = Anomaly::query()
            ->with(AnomalyResource::RELATIONS)
            ->onActiveOrders()
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => match ($state) {
                'open' => $query->open(),
                'all' => $query,
                default => $query->where('state', $state),
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where('source', $source))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['rule_code'] ?? null, fn (Builder $query, string $code) => $query->where('rule_code', $code))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('detected_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->where('detected_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['mission_order_id'] ?? null, fn (Builder $query, $id) => $query->where('mission_order_id', (int) $id))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $id) => $query->where('employee_id', (int) $id))
            ->when($filters['control_run_id'] ?? null, fn (Builder $query, $id) => $query->where('control_run_id', (int) $id))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereHas('missionOrder', fn (Builder $order) => $order->where('reference', 'ilike', $like))
                    ->orWhereHas('employee', fn (Builder $employee) => $employee->search($search))
                    ->orWhere('description', 'ilike', $like));
            })
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
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
