<?php

namespace App\Modules\Product\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a product row exists (fresh create, or a duplicate's copy).
 * This is Product's side of the cross-module boundary: other modules react
 * to this event rather than Product reaching into their tables, or them
 * reaching into Product's. Inventory's CreateInventoryRecord listener is
 * the first consumer — see app/Modules/Inventory/Listeners.
 */
class ProductCreated
{
    use Dispatchable;

    public function __construct(public readonly int $productId) {}
}
