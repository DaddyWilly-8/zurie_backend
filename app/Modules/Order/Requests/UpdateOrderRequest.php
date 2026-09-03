<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /admin/orders/{id} — admin only. Only status/notes are ever
 * mutable here; everything else (customer snapshot, items, totalAmount) is
 * fixed at checkout time and never edited after the fact.
 *
 * `cancelled` is deliberately NOT in the accepted status list —
 * cancellation has a restock side effect this endpoint knows nothing
 * about, so it only ever happens through the dedicated
 * `POST /admin/orders/{id}/cancel` endpoint. Sending `status: cancelled`
 * here fails validation (422) rather than silently cancelling without
 * restocking.
 */
class UpdateOrderRequest extends FormRequest
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
            'status' => ['sometimes', 'required', 'in:new,confirmed,processing,ready_for_delivery,delivered'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
