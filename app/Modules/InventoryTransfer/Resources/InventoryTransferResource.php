<?php

namespace App\Modules\InventoryTransfer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transferNumber' => $this->transfer_number,
            'type' => $this->type,
            'sourceOutletId' => $this->source_outlet_id,
            'destinationOutletId' => $this->destination_outlet_id,
            'sourceCostCenterId' => $this->source_cost_center_id,
            'destinationCostCenterId' => $this->destination_cost_center_id,
            'transferDate' => $this->transfer_date?->toDateString(),
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'productId' => $item->product_id,
                'quantity' => $item->quantity,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
