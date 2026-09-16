<?php

namespace App\Modules\PriceList\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isDefault' => $this->is_default,
            'outletId' => $this->outlet_id,
            'customerId' => $this->customer_id,
            'validFrom' => $this->valid_from?->toDateString(),
            'validTo' => $this->valid_to?->toDateString(),
            'isActive' => $this->is_active,
            'items' => PriceListItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
