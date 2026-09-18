<?php

namespace App\Modules\ProformaInvoice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProformaInvoiceRequest extends FormRequest
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
            'proformaDate' => ['nullable', 'date'],
            'expiryDate' => ['nullable', 'date', 'after_or_equal:proformaDate'],
            'salesOutletId' => ['required', 'integer', 'exists:sales_outlets,id'],
            'stakeholderId' => ['required', 'integer', 'exists:stakeholders,id'],
            'currencyId' => ['nullable', 'integer', 'exists:currencies,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unitPrice' => ['required', 'numeric', 'min:0'],
        ];
    }
}
