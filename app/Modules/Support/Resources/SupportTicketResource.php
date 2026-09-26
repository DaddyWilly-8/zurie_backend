<?php

namespace App\Modules\Support\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing('customerAccount', 'attendedBy');

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'organizationName' => $this->organization_name,
            'notes' => $this->notes,
            'status' => $this->status,
            'customer' => [
                'id' => $this->customerAccount->id,
                'name' => $this->customerAccount->name,
                'email' => $this->customerAccount->email,
            ],
            'attendedBy' => $this->attendedBy !== null ? [
                'id' => $this->attendedBy->id,
                'name' => $this->attendedBy->name,
                'email' => $this->attendedBy->email,
            ] : null,
            'closedAt' => $this->closed_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
