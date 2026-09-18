<?php

namespace App\Modules\Procurement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'measurementUnitId' => $this->measurement_unit_id,
            'conversionFactor' => (float) $this->conversion_factor,
            'quantity' => (float) $this->quantity,
            'rate' => (float) $this->rate,
            'vatPercentage' => (float) $this->vat_percentage,
            'lineTotal' => (float) $this->line_total,
        ];
    }
}
