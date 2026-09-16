<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POS never accepts prices/product names from the client, same rule as
 * StoreOrderRequest — see PosController/OrderService::posSale().
 */
class StorePosSaleRequest extends FormRequest
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
            'outletId' => ['required', 'integer', 'exists:sales_outlets,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // Either an existing customer id, or name+phone for a
            // guest/walk-in — mutually exclusive-ish but not enforced
            // strictly here; OrderService::posSale() prefers customerId
            // when present.
            'customerId' => ['nullable', 'integer', 'exists:customers,id'],
            'customerName' => ['required_without:customerId', 'string', 'max:255'],
            'customerPhone' => ['required_without:customerId', 'string', 'max:50'],
            'whatsappNumber' => ['nullable', 'string', 'max:50'],
            'customerEmail' => ['nullable', 'email', 'max:255'],
            'couponCode' => ['nullable', 'string', 'max:50'],
        ];
    }
}
