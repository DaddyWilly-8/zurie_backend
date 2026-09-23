<?php

namespace App\Modules\Customer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'whatsappNumber' => $this->whatsapp_number,
            'email' => $this->email,
            // has_customer_account (an EXISTS(), always 0 or 1, never SQL
            // NULL) is attached by CustomerService::withAccountFlag() on
            // admin-facing queries; falls back to the pre-split user_id
            // check only when that subquery genuinely wasn't run (e.g. a
            // Resource built directly in a test).
            'isRegistered' => (bool) ($this->has_customer_account ?? ($this->user_id !== null)),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
