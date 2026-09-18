<?php

namespace App\Modules\MeasurementUnit\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeasurementUnitRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255', 'unique:measurement_units,name'],
            'symbol' => ['required', 'string', 'max:10', 'unique:measurement_units,symbol'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
