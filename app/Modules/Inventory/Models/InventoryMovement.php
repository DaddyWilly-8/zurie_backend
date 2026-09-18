<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only — a row here is never updated or deleted once written; it's
 * the audit trail `inventory.quantity`'s cached balance is derived from.
 * See Zurie_V2_Architecture_Design (2).md §12 / §35 (0.5).
 */
#[Fillable(['product_id', 'sales_outlet_id', 'type', 'quantity', 'reason', 'reference_type', 'reference_id', 'created_by'])]
class InventoryMovement extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
