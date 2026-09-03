<?php

namespace App\Modules\Order\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/orders — list item. Trimmed compared to OrderResource (no
 * `items`/`customerEmail`/`notes`/`updatedAt`), but does include
 * `customerPhone`/`whatsappNumber` — an admin scanning the order table
 * needs a quick way to contact the customer without opening the detail
 * view. Same reasoning as Product's list-vs-detail resource split, just a
 * less trimmed cut for this table's actual usage.
 */
class OrderListResource extends JsonResource
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
            'totalAmount' => (float) $this->total_amount,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
