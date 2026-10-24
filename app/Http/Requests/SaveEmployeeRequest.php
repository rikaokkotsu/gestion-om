<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $required = $employee instanceof Employee ? 'sometimes' : 'required';

        return [
            'matricule' => [$required, 'string', 'max:64', Rule::unique('employees', 'matricule')->ignore($employee instanceof Employee ? $employee->id : null)],
            'last_name' => [$required, 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'current_place_id' => ['nullable', 'integer', 'exists:places,id'],
            'assignment_date' => ['nullable', 'date'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'is_validator' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
