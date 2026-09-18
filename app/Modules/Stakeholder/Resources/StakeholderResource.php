<?php

namespace App\Modules\Stakeholder\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StakeholderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'type' => $this->type,
            'tin' => $this->tin,
            'vrn' => $this->vrn,
            'address' => $this->address,
            'email' => $this->email,
            'website' => $this->website,
            'remarks' => $this->remarks,
            'whatsappNumber' => $this->whatsapp_number,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
