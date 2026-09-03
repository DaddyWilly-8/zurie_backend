<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Product\Events\ProductDeleted;

/**
 * Reacts to Product's ProductDeleted event rather than Product calling into
 * Inventory directly — same event-driven, one-directional pattern as
 * CreateInventoryRecord. Cleans up the inventory row a deleted product
 * leaves behind, since product_id has no FK/cascade to do it automatically.
 */
class DeleteInventoryRecord
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function handle(ProductDeleted $event): void
    {
        $this->inventoryService->deleteForProduct($event->productId);
    }
}
