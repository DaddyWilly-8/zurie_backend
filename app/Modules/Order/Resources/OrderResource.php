<?php

namespace App\Modules\Order\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single order — used for both the checkout response (POST /orders) and
 * the admin detail/update responses (GET/PATCH /orders/{id}). Same shape
 * for all three; checkout is public but this never includes buyingPrice
 * (see items below), so there's nothing sensitive to gate here the way
 * Product's admin/public split has to.
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderNumber' => $this->order_number,
            'status' => $this->status,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'whatsappNumber' => $this->whatsapp_number,
            'customerEmail' => $this->customer_email,
            'totalAmount' => (float) $this->total_amount,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'productId' => $item->product_id,
                'productName' => $item->product_name,
                'quantity' => $item->quantity,
                'unitSellingPrice' => (float) $item->unit_selling_price,
                'lineTotal' => (float) $item->line_total,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
