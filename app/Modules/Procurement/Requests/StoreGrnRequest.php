<?php

namespace App\Modules\Procurement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGrnRequest extends FormRequest
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
            'purchaseOrderId' => ['required', 'integer', 'exists:purchase_orders,id'],
            'dateReceived' => ['nullable', 'date'],
            'costFactor' => ['nullable', 'numeric', 'min:0.0001'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchaseOrderItemId' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'lines.*.quantityReceived' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
