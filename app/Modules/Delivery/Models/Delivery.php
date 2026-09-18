<?php

namespace App\Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['delivery_number', 'order_id', 'date_dispatched', 'notes'])]
class Delivery extends Model
{
    protected function casts(): array
    {
        return ['date_dispatched' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class);
    }

    // Deliberately no relation to Order — cross-module reference, no FK,
    // same reasoning as ReceiptOrder's own docblock.
}
