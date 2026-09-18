<?php

namespace App\Modules\Procurement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // One query per item (N+1 for a PO with many lines) rather than a
        // pivot-column aggregate load — Eloquent's withSum() shortcut sums
        // a column on the *related* table, not the pivot, and a PO's item
        // count is small enough in practice that this isn't worth the
        // extra complexity of a custom aggregate subquery. Surfaces the
        // "how much is actually still unreceived" figure the Receive
        // Goods dialog needs — previously only enforced server-side in
        // GrnService, never shown to the admin filling the form.
        $receivedQuantity = (float) DB::table('grn_purchase_order_item')
            ->where('purchase_order_item_id', $this->id)
            ->sum('quantity_received');

        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'measurementUnitId' => $this->measurement_unit_id,
            'conversionFactor' => (float) $this->conversion_factor,
            'quantity' => (float) $this->quantity,
            'rate' => (float) $this->rate,
            'vatPercentage' => (float) $this->vat_percentage,
            'lineTotal' => (float) $this->line_total,
            'receivedQuantity' => $receivedQuantity,
            'remainingQuantity' => max(0, (float) $this->quantity - $receivedQuantity),
        ];
    }
}
