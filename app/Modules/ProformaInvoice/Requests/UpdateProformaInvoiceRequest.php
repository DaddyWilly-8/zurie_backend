<?php

namespace App\Modules\ProformaInvoice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProformaInvoiceRequest extends FormRequest
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
            'expiryDate' => ['nullable', 'date'],
            'salesOutletId' => ['nullable', 'integer', 'exists:sales_outlets,id'],
            'stakeholderId' => ['nullable', 'integer', 'exists:stakeholders,id'],
            'currencyId' => ['nullable', 'integer', 'exists:currencies,id'],
            'notes' => ['nullable', 'string'],
            // Items are optional on update — omitting them leaves the
            // existing line items untouched (only header fields change);
            // present, they're a full delete+recreate (see the service).
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.productId' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001'],
            'items.*.unitPrice' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
