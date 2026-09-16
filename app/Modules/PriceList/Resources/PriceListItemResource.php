<?php

namespace App\Modules\PriceList\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'price' => (float) $this->price,
            'salePrice' => $this->sale_price !== null ? (float) $this->sale_price : null,
        ];
    }
}
