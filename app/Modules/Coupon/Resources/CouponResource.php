<?php

namespace App\Modules\Coupon\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => (float) $this->value,
            'minOrderAmount' => $this->min_order_amount !== null ? (float) $this->min_order_amount : null,
            'maxUses' => $this->max_uses,
            'usedCount' => $this->used_count,
            'validFrom' => $this->valid_from?->toDateString(),
            'validTo' => $this->valid_to?->toDateString(),
            'isActive' => $this->is_active,
        ];
    }
}
