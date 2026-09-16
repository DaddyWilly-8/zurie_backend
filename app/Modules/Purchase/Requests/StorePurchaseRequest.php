<?php

namespace App\Modules\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
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
            'supplierId' => ['required', 'integer', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.costPrice' => ['required', 'numeric', 'min:0'],
            'amountPaid' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'costCenterId' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ];
    }
}
