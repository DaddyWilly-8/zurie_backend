<?php

namespace App\Modules\Product\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a product row is deleted. Mirrors ProductCreated — other
 * modules react to this rather than Product reaching into their tables.
 * Inventory's DeleteInventoryRecord listener is the first consumer, cleaning
 * up the now-meaningless inventory row left behind by the cross-module,
 * no-FK product_id reference (see app/Modules/Inventory/Listeners).
 */
class ProductDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $productId) {}
}
