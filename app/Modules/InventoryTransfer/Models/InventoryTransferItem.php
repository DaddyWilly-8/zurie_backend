<?php

namespace App\Modules\InventoryTransfer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventory_transfer_id', 'product_id', 'quantity'])]
class InventoryTransferItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function inventoryTransfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class);
    }
}
