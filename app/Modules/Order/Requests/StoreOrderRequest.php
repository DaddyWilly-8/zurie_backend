<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders — public checkout. Deliberately accepts only customer
 * details and { productId, quantity } lines: no price, no product name,
 * no total. OrderService::checkout() looks all of that up server-side via
 * ProductService — see zurie-backend-implementation-spec.md §7.
 */
class StoreOrderRequest extends FormRequest
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
            'customerName' => ['required', 'string', 'max:255'],
            'customerPhone' => ['required', 'string', 'max:50'],
            'whatsappNumber' => ['nullable', 'string', 'max:50'],
            'customerEmail' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'couponCode' => ['nullable', 'string', 'max:50'],
        ];
    }
}
