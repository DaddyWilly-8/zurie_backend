<?php

namespace App\Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['delivery_id', 'order_item_id', 'quantity_dispatched'])]
class DeliveryOrderItem extends Model
{
    protected $table = 'delivery_order_item';

    protected function casts(): array
    {
        return ['quantity_dispatched' => 'integer'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
