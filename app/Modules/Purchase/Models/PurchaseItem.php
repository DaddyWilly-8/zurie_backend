<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_id', 'product_id', 'quantity', 'cost_price', 'line_total', 'vat_percentage', 'vat_amount'])]
class PurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'vat_percentage' => 'double',
            'vat_amount' => 'double',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
