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
            'isRegistered' => $this->user_id !== null,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
