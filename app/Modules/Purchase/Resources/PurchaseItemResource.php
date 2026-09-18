<?php

namespace App\Modules\Purchase\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'productId' => $this->product_id,
            'quantity' => $this->quantity,
            'costPrice' => (float) $this->cost_price,
            'lineTotal' => (float) $this->line_total,
            'vatPercentage' => (float) $this->vat_percentage,
            'vatAmount' => (float) $this->vat_amount,
        ];
    }
}
