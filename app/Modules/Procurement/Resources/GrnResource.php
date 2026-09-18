<?php

namespace App\Modules\Procurement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grnNumber' => $this->grn_number,
            'dateReceived' => $this->date_received?->toDateString(),
            'costFactor' => (float) $this->cost_factor,
            'purchaseOrderId' => $this->grnable_id,
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'purchaseOrderItemId' => $item->id,
                'productId' => $item->product_id,
                'quantityReceived' => (float) $item->pivot->quantity_received,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
