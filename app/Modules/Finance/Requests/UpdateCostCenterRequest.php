<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCostCenterRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:cost_centers,id'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
