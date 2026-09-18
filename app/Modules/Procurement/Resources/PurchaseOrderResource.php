<?php

namespace App\Modules\Procurement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'poNumber' => $this->po_number,
            'orderDate' => $this->order_date?->toDateString(),
            'dateRequired' => $this->date_required?->toISOString(),
            'stakeholderId' => $this->stakeholder_id,
            'currencyId' => $this->currency_id,
            'exchangeRate' => (float) $this->exchange_rate,
            'status' => $this->status,
            'totalAmount' => (float) $this->total_amount,
            'vatAmount' => (float) $this->vat_amount,
            'notes' => $this->notes,
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
