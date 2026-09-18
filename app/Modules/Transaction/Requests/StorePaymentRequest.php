<?php

namespace App\Modules\Transaction\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
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
            'transactionDate' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string'],
            'creditLedgerId' => ['required', 'integer', 'exists:ledgers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.debitLedgerId' => ['required', 'integer', 'exists:ledgers,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'purchaseOrders' => ['nullable', 'array'],
            'purchaseOrders.*.purchaseOrderId' => ['required_with:purchaseOrders', 'integer', 'exists:purchase_orders,id'],
            'purchaseOrders.*.amountApplied' => ['required_with:purchaseOrders', 'numeric', 'min:0.01'],
        ];
    }
}
