<?php

namespace App\Modules\InventoryTransfer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'transfer_number',
    'type',
    'source_outlet_id',
    'destination_outlet_id',
    'source_cost_center_id',
    'destination_cost_center_id',
    'transfer_date',
    'notes',
])]
class InventoryTransfer extends Model
{
    protected function casts(): array
    {
        return ['transfer_date' => 'date'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }
}
