<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveMissionOrder;
use App\Enums\AdministrativeStatus;
use App\Enums\MissionOrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMissionOrderRequest;
use App\Http\Resources\MissionOrderResource;
use App\Http\Resources\MissionOrderSummaryResource;
use App\Models\Employee;
use App\Models\MissionOrder;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MissionOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(MissionOrderType::class)],
            'administrative_status' => ['nullable', Rule::enum(AdministrativeStatus::class)],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $orders = MissionOrder::query()
            ->with(MissionOrderSummaryResource::RELATIONS)
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->where('reference', 'ilike', $like)
                    ->orWhereHas('employees.employee', fn (Builder $employee) => $employee->search($search)));
            })
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['administrative_status'] ?? null, fn (Builder $query, string $status) => $query->where('administrative_status', $status))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $id) => $query->concerning((int) $id))
            ->orderByRaw('departure_at desc nulls last')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return MissionOrderSummaryResource::collection($orders);
    }

    public function show(MissionOrder $missionOrder): MissionOrderResource
    {
        return new MissionOrderResource($missionOrder->load(MissionOrderResource::RELATIONS));
    }

    public function store(SaveMissionOrderRequest $request, SaveMissionOrder $save): JsonResponse
    {
        $order = $save(null, $request->validated(), $this->actor($request));

        return $this->show($order)->response()->setStatusCode(201);
    }

    public function update(SaveMissionOrderRequest $request, MissionOrder $missionOrder, SaveMissionOrder $save): MissionOrderResource
    {
        $order = $save($missionOrder, $request->validated(), $this->actor($request));

        return $this->show($order);
    }

    /**
     * Suppression logique : l'OM disparaît des listes, l'historique
     * d'affectation qu'il a produit reste en place.
     */
    public function destroy(Request $request, MissionOrder $missionOrder): JsonResponse
    {
        Audit::record('mission_order.deleted', $missionOrder, SaveMissionOrder::snapshot($missionOrder), [], $this->actor($request));
        $missionOrder->delete();

        return response()->json(null, 204);
    }

    /**
     * Fiche employé : OM dont il est missionnaire ou validateur.
     */
    public function forEmployee(Employee $employee): AnonymousResourceCollection
    {
        $orders = MissionOrder::query()
            ->with(MissionOrderSummaryResource::RELATIONS)
            ->concerning($employee->id)
            ->orderByRaw('departure_at desc nulls last')
            ->orderByDesc('id')
            ->get();

        return MissionOrderSummaryResource::collection($orders);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
