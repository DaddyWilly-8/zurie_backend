<?php

namespace App\Modules\Expense\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'paymentMethod' => $this->payment_method,
            'description' => $this->description,
            'costCenterId' => $this->cost_center_id,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
