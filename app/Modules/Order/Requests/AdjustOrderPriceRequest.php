<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/orders/{order}/price-adjustment — admin only. `reason` is
 * required (not just recommended) so a negotiated-price cut always leaves
 * an auditable explanation, same posture as cancel()'s restock note in the
 * activity log.
 */
class AdjustOrderPriceRequest extends FormRequest
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
            'newTotalAmount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
