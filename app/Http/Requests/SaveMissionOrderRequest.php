<?php

namespace App\Http\Requests;

use App\Enums\AdministrativeStatus;
use App\Enums\ConsumptionType;
use App\Enums\CostNature;
use App\Enums\MissionOrderType;
use App\Enums\TravelDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Seule l'intégrité est vérifiée ici (types, références existantes). Les
 * incohérences métier (retour avant départ, quantité négative, validateur
 * missionnaire…) s'enregistrent et sont signalées par le contrôle.
 */
class SaveMissionOrderRequest extends FormRequest
{
    private const MAX_AMOUNT = 1_000_000_000_000;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $amount = ['nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT];
        $place = ['nullable', 'integer', 'exists:places,id'];
        $quantity = ['required', 'integer', 'between:-1000,1000'];

        return [
            'reference' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', Rule::enum(MissionOrderType::class)],
            'mission_object_id' => ['nullable', 'integer', 'exists:mission_objects,id'],
            'departure_at' => ['nullable', 'date'],
            'return_at' => ['nullable', 'date'],
            'departure_place_id' => $place,
            'destination_place_id' => $place,
            'declared_duration' => ['nullable', 'integer', 'between:-10000,10000'],
            'additional_costs' => $amount,
            'assignment_allowance' => $amount,
            'validator_id' => ['nullable', 'integer', 'exists:employees,id'],
            'validated_at' => ['nullable', 'date'],
            'administrative_status' => ['sometimes', 'required', Rule::enum(AdministrativeStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],

            'costs' => ['sometimes', 'array', 'max:50'],
            'costs.*.nature' => ['required', Rule::enum(CostNature::class)],
            'costs.*.label' => ['nullable', 'string', 'max:255'],
            'costs.*.amount' => $amount,

            'legs' => ['sometimes', 'array', 'max:40'],
            'legs.*.direction' => ['required', Rule::enum(TravelDirection::class)],
            'legs.*.transport_mode_id' => ['nullable', 'integer', 'exists:transport_modes,id'],
            'legs.*.from_place_id' => $place,
            'legs.*.to_place_id' => $place,
            'legs.*.cost' => $amount,

            'employees' => ['sometimes', 'array', 'max:100'],
            'employees.*.employee_id' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'employees.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'employees.*.declared_per_diem' => $amount,
            'employees.*.quantities' => ['sometimes', 'array'],
            'employees.*.quantities.*.consumption_type' => ['required', Rule::enum(ConsumptionType::class)],
            'employees.*.quantities.*.zone_id' => ['required', 'integer', 'exists:zones,id'],
            'employees.*.quantities.*.quantity' => $quantity,
            'employees.*.totals' => ['sometimes', 'array'],
            'employees.*.totals.*.consumption_type' => ['required', Rule::enum(ConsumptionType::class)],
            'employees.*.totals.*.total' => $quantity,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference' => 'référence',
            'type' => 'type',
            'mission_object_id' => 'objet',
            'departure_at' => 'départ',
            'return_at' => 'retour',
            'departure_place_id' => 'lieu de départ',
            'destination_place_id' => 'destination',
            'declared_duration' => 'durée déclarée',
            'additional_costs' => 'frais supplémentaires',
            'assignment_allowance' => 'indemnité d\'affectation',
            'validator_id' => 'validateur',
            'validated_at' => 'date de validation',
            'administrative_status' => 'statut administratif',
            'notes' => 'observations',
            'costs.*.nature' => 'nature du frais',
            'costs.*.label' => 'précision du frais',
            'costs.*.amount' => 'montant du frais',
            'legs.*.direction' => 'sens de l\'étape',
            'legs.*.transport_mode_id' => 'moyen de transport',
            'legs.*.from_place_id' => 'départ de l\'étape',
            'legs.*.to_place_id' => 'arrivée de l\'étape',
            'legs.*.cost' => 'frais de l\'étape',
            'employees.*.employee_id' => 'missionnaire',
            'employees.*.category_id' => 'catégorie retenue',
            'employees.*.declared_per_diem' => 'per diem déclaré',
            'employees.*.quantities.*.consumption_type' => 'type de consommation',
            'employees.*.quantities.*.zone_id' => 'zone',
            'employees.*.quantities.*.quantity' => 'quantité',
            'employees.*.totals.*.consumption_type' => 'type de consommation',
            'employees.*.totals.*.total' => 'total déclaré',
        ];
    }

    /**
     * Une même consommation ne se saisit qu'une fois par zone, et un total qu'une fois par type.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $employees = $this->input('employees');
            if (! is_array($employees)) {
                return;
            }

            foreach ($employees as $index => $employee) {
                if (! is_array($employee)) {
                    continue;
                }
                $quantities = array_map(
                    fn ($line) => is_array($line) ? ($line['consumption_type'] ?? '').'|'.($line['zone_id'] ?? '') : '',
                    is_array($employee['quantities'] ?? null) ? $employee['quantities'] : [],
                );
                if (count($quantities) !== count(array_unique($quantities))) {
                    $validator->errors()->add("employees.{$index}.quantities", 'Une même consommation est saisie deux fois pour la même zone.');
                }
                $totals = array_map(
                    fn ($line) => is_array($line) ? (string) ($line['consumption_type'] ?? '') : '',
                    is_array($employee['totals'] ?? null) ? $employee['totals'] : [],
                );
                if (count($totals) !== count(array_unique($totals))) {
                    $validator->errors()->add("employees.{$index}.totals", 'Un même total est saisi deux fois.');
                }
            }
        }];
    }
}
