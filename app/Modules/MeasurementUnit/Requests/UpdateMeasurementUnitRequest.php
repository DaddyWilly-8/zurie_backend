<?php

namespace App\Modules\MeasurementUnit\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeasurementUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unit = $this->route('measurementUnit');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('measurement_units', 'name')->ignore($unit)],
            'symbol' => ['sometimes', 'string', 'max:10', Rule::unique('measurement_units', 'symbol')->ignore($unit)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
