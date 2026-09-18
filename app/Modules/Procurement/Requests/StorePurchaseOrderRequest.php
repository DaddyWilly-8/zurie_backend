<?php

namespace App\Modules\Procurement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
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
            // Nullable — a null stakeholder is the reference doc's "Cash
            // Purchase" case, matching PurchaseOrder.stakeholder_id.
            'stakeholderId' => ['nullable', 'integer', 'exists:stakeholders,id'],
            'currencyId' => ['nullable', 'integer', 'exists:currencies,id'],
            'dateRequired' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            // When true, the controller immediately posts a GRN receiving
            // every line in full right after creating the PO — same
            // one-step "instant receive" behavior the old standalone
            // Purchases flow had, now built on PurchaseOrder+Grn instead
            // of a separate model.
            'instantReceive' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'],
            'items.*.measurementUnitId' => ['required', 'integer', 'exists:measurement_units,id'],
            'items.*.conversionFactor' => ['nullable', 'numeric', 'min:0.0001'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'items.*.vatPercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
