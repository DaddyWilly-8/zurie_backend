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
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
