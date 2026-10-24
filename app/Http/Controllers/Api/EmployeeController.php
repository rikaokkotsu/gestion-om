<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveEmployee;
use App\Enums\AssignmentSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    private const RELATIONS = ['service', 'category', 'currentPlace.zone'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'current_place_id' => ['nullable', 'integer'],
            'is_validator' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive,all'],
        ])->validate();

        $employees = Employee::query()
            ->with(self::RELATIONS)
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->search($search))
            ->when($filters['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->when($filters['current_place_id'] ?? null, fn ($query, $id) => $query->where('current_place_id', $id))
            ->when(isset($filters['is_validator']), fn ($query) => $query->where('is_validator', $request->boolean('is_validator')))
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($query) => $query->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $employee->load([
            ...self::RELATIONS,
            'assignmentHistories' => fn ($query) => $query->with(['previousPlace', 'newPlace', 'recordedBy'])->latest('id'),
        ]);

        return new EmployeeResource($employee);
    }

    public function store(SaveEmployeeRequest $request, SaveEmployee $save): EmployeeResource
    {
        $employee = $this->save($request, $save, null);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function update(SaveEmployeeRequest $request, Employee $employee, SaveEmployee $save): EmployeeResource
    {
        $this->save($request, $save, $employee);

        return $this->show($employee);
    }

    private function save(SaveEmployeeRequest $request, SaveEmployee $save, ?Employee $employee): Employee
    {
        $data = $request->safe()->except('assignment_date');
        $date = $request->validated('assignment_date');
        $user = $request->user();

        return $save(
            $employee,
            $data,
            AssignmentSource::Manual,
            $user instanceof User ? $user : null,
            $date === null ? null : Carbon::parse($date),
        );
    }
}
