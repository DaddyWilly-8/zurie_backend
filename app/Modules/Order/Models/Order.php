<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_number',
    'customer_id',
    'stakeholder_id',
    'customer_name',
    'customer_phone',
    'whatsapp_number',
    'customer_email',
    'status',
    'source',
    'outlet_id',
    'total_amount',
    'discount_amount',
    'vat_amount',
    'coupon_id',
    'currency_id',
    'exchange_rate',
    'notes',
])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'exchange_rate' => 'double',
        ];
    }

    /**
     * Route-model-binding resolves {order} by order_number instead of the
     * primary key — every GET/PATCH/POST-cancel route on this resource
     * (show/update/cancel) uses this, so the raw internal id is never
     * part of a URL, an error message, or anything else user-facing. `id`
     * still exists and is still the actual primary key/FK target for
     * order_items — this only changes what route binding resolves by.
     */
    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    /**
     * Same-module — order_items.order_id is a real FK (cascadeOnDelete),
     * unlike order_items.product_id, which is a cross-module reference
     * into Product with no FK.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Deliberately no relation back into Customer — customer_id is a
    // cross-module reference (no FK). Order snapshots customer_name/
    // customer_phone/whatsapp_number/customer_email at order time and
    // never needs a live Eloquent relationship into Customer's table,
    // same reasoning as Product's category_id vs. Inventory's product_id.
}
