<?php

namespace App\Http\Controllers\Api;

use App\Control\RuleRegistry;
use App\Enums\AdministrativeStatus;
use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;
use App\Enums\AnomalyPriority;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Enums\ConsumptionType;
use App\Enums\ControlStatus;
use App\Enums\CostNature;
use App\Enums\MissionOrderType;
use App\Enums\Referential;
use App\Enums\TravelDirection;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MissionObject;
use App\Models\Place;
use App\Models\Service;
use App\Models\TransportMode;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReferentialController extends Controller
{
    /**
     * Toutes les listes en un appel, pour alimenter les formulaires.
     */
    public function all(RuleRegistry $registry): JsonResponse
    {
        return response()->json(['data' => [
            'zones' => Zone::query()->orderBy('id')->get(['id', 'code', 'label']),
            'categories' => Category::query()->orderBy('id')->get(),
            'places' => Place::query()->with('zone:id,code,label')->orderBy('name')->get(),
            'services' => Service::query()->orderBy('name')->get(),
            'mission_objects' => MissionObject::query()->orderBy('label')->get(),
            'transport_modes' => TransportMode::query()->orderBy('label')->get(),
            'consumption_types' => self::options(ConsumptionType::cases()),
            'mission_order_types' => self::options(MissionOrderType::cases()),
            'administrative_statuses' => self::options(AdministrativeStatus::cases()),
            'control_statuses' => self::options(ControlStatus::cases()),
            'cost_natures' => self::options(CostNature::cases()),
            'travel_directions' => self::options(TravelDirection::cases()),
            'anomaly_categories' => self::options(AnomalyCategory::cases()),
            'anomaly_natures' => self::options(AnomalyNature::cases()),
            'anomaly_priorities' => self::options(AnomalyPriority::cases()),
            'anomaly_sources' => self::options(AnomalySource::cases()),
            'anomaly_states' => self::options(AnomalyState::cases()),
            'rules' => array_values(array_map(fn ($rule) => [
                'code' => $rule->definition()->code,
                'category' => $rule->definition()->category->value,
                'label' => $rule->definition()->label,
            ], $registry->all())),
        ]]);
    }

    /**
     * Valeurs d'une énumération, avec leur libellé, pour les listes de choix.
     *
     * @param  list<ConsumptionType|MissionOrderType|AdministrativeStatus|ControlStatus|CostNature|TravelDirection|AnomalyCategory|AnomalyNature|AnomalyPriority|AnomalySource|AnomalyState>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $cases): array
    {
        return array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    public function index(Referential $referential): JsonResponse
    {
        $items = $referential->model()::query()
            ->with($referential->relations())
            ->orderBy($referential->orderColumn())
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, Referential $referential): JsonResponse
    {
        $data = Validator::make($request->all(), $referential->rules(null))->validate();
        $item = $referential->model()::create($data);

        return response()->json(['data' => $item->load($referential->relations())], 201);
    }

    public function update(Request $request, Referential $referential, int $id): JsonResponse
    {
        $item = $referential->model()::query()->findOrFail($id);
        $data = Validator::make($request->all(), $referential->rules($item))->validate();
        $item->update($data);

        return response()->json(['data' => $item->load($referential->relations())]);
    }
}
