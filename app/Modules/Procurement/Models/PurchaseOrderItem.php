<?php

namespace App\Modules\Procurement\Models;

use App\Modules\MeasurementUnit\Models\MeasurementUnit;
use App\Modules\Product\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'purchase_order_id',
    'product_id',
    'measurement_unit_id',
    'conversion_factor',
    'quantity',
    'rate',
    'vat_percentage',
    'line_total',
])]
class PurchaseOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'conversion_factor' => 'double',
            'quantity' => 'double',
            'rate' => 'double',
            'vat_percentage' => 'double',
            'line_total' => 'double',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    /**
     * Every GRN line that has received against this item, across possibly
     * several GRNs (partial deliveries) — see grn_purchase_order_item's
     * migration docblock.
     */
    public function grns(): BelongsToMany
    {
        return $this->belongsToMany(Grn::class, 'grn_purchase_order_item')
            ->withPivot('quantity_received')
            ->withTimestamps();
    }
}
