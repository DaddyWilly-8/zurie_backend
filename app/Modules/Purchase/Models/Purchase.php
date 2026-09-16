<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchase_number', 'supplier_id', 'total_amount', 'amount_paid', 'notes'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    /**
     * Same-module — purchase_items.purchase_id is a real FK
     * (cascadeOnDelete), unlike purchase_items.product_id, which is a
     * cross-module reference into Product with no FK.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    // Deliberately no relation to Supplier here despite the real FK on
    // supplier_id — PurchaseService reaches Supplier data via
    // SupplierService, a cross-module Service call, not an Eloquent
    // relationship (Extensibility Constitution, Rule 2). The FK exists
    // for referential integrity at the DB level, not for Eloquent ->
    // chaining across modules.
}
