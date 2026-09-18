<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'po_number',
    'order_date',
    'date_required',
    'stakeholder_id',
    'currency_id',
    'exchange_rate',
    'status',
    'total_amount',
    'vat_amount',
    'notes',
])]
class PurchaseOrder extends Model
{
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'date_required' => 'datetime',
            'exchange_rate' => 'double',
            'total_amount' => 'double',
            'vat_amount' => 'double',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function grns(): MorphMany
    {
        return $this->morphMany(Grn::class, 'grnable');
    }
}
