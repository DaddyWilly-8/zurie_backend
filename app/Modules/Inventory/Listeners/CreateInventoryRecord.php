<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Product\Events\ProductCreated;

/**
 * Reacts to Product's ProductCreated event rather than Product calling into
 * Inventory directly — keeps the dependency one-directional and event-driven
 * per the modular monolith's cross-module boundary rule.
 */
class CreateInventoryRecord
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function handle(ProductCreated $event): void
    {
        $this->inventoryService->provisionForProduct($event->productId);
    }
}
