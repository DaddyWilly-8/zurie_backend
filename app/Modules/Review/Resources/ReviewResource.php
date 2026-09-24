<?php

namespace App\Modules\Review\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'customerId' => $this->customer_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status,
            'productName' => $this->product_name,
            // Public product pages show only the reviewer's first name;
            // the admin moderation list gets the full name.
            'reviewerName' => $this->customer_name !== null ? strtok((string) $this->customer_name, ' ') : null,
            'customerName' => $request->is('api/v1/admin/*') ? $this->customer_name : null,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
