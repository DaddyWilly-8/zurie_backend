<?php

namespace App\Modules\Purchase\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchaseNumber' => $this->purchase_number,
            'supplierId' => $this->supplier_id,
            'totalAmount' => (float) $this->total_amount,
            'vatAmount' => (float) $this->vat_amount,
            'amountPaid' => (float) $this->amount_paid,
            'currencyId' => $this->currency_id,
            'exchangeRate' => $this->exchange_rate !== null ? (float) $this->exchange_rate : null,
            'notes' => $this->notes,
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
