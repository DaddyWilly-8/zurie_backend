<?php

namespace App\Modules\Outlet\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesOutletRequest extends FormRequest
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
            'type' => ['sometimes', 'in:physical,online'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'costCenterId' => ['sometimes', 'nullable', 'integer', 'exists:cost_centers,id'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
