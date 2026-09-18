<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['grn_number', 'date_received', 'cost_factor', 'grnable_type', 'grnable_id', 'notes'])]
class Grn extends Model
{
    protected function casts(): array
    {
        return [
            'date_received' => 'date',
            'cost_factor' => 'double',
        ];
    }

    public function grnable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrderItem::class, 'grn_purchase_order_item')
            ->withPivot('quantity_received')
            ->withTimestamps();
    }
}
