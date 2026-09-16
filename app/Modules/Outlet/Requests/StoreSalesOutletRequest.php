<?php

namespace App\Modules\Outlet\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesOutletRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:physical,online'],
            'address' => ['nullable', 'string', 'max:255'],
            'costCenterId' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ];
    }
}
