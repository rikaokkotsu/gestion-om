<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveMissionOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMissionOrderRequest;
use App\Http\Resources\MissionOrderResource;
use App\Http\Resources\MissionOrderSummaryResource;
use App\Models\Employee;
use App\Models\MissionOrder;
use App\Models\User;
use App\Search\MissionOrderSearch;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MissionOrderController extends Controller
{
    /**
     * Recherche multicritère (§ 14.1).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = MissionOrderSearch::query(MissionOrderSearch::validate($request))
            ->with(MissionOrderSummaryResource::RELATIONS)
            ->withCount('openAnomalies')
            ->latestDeparture()
            ->paginate(25)
            ->withQueryString();

        return MissionOrderSummaryResource::collection($orders);
    }

    public function show(MissionOrder $missionOrder): MissionOrderResource
    {
        return new MissionOrderResource($missionOrder->load(MissionOrderResource::RELATIONS)->loadCount('openAnomalies'));
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
            ->withCount('openAnomalies')
            ->concerning($employee->id)
            ->latestDeparture()
            ->get();

        return MissionOrderSummaryResource::collection($orders);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
