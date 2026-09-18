<?php

namespace App\Modules\Transaction\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
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
            'debitLedgerId' => ['required', 'integer', 'exists:ledgers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.creditLedgerId' => ['required', 'integer', 'exists:ledgers,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'sales' => ['nullable', 'array'],
            'sales.*.orderId' => ['required_with:sales', 'integer'],
            'sales.*.amountApplied' => ['required_with:sales', 'numeric', 'min:0.01'],
        ];
    }
}
