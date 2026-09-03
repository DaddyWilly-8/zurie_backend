<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
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
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'stockStatus' => ['sometimes', 'required', 'in:IN_STOCK,LOW_STOCK,OUT_OF_STOCK'],
        ];
    }
}
