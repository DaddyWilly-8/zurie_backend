<?php

namespace App\Modules\InventoryTransfer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryTransferRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:internal,external,cost_center_change'],
            'sourceOutletId' => ['required', 'integer', 'exists:sales_outlets,id'],
            'destinationOutletId' => ['nullable', 'integer', 'exists:sales_outlets,id'],
            'sourceCostCenterId' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'destinationCostCenterId' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'transferDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
