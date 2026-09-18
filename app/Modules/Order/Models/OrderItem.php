<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'product_id',
    'product_name',
    'unit_buying_price',
    'unit_selling_price',
    'quantity',
    'line_total',
    'vat_percentage',
    'vat_amount',
])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'unit_buying_price' => 'decimal:2',
            'unit_selling_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'vat_percentage' => 'double',
            'vat_amount' => 'double',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // No relation into Product — product_id is a cross-module reference,
    // no FK. product_name/unit_buying_price/unit_selling_price are all
    // snapshotted at order time precisely so this row never needs to look
    // the live product up again.
}
