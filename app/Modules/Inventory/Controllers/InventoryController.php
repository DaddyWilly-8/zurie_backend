<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Requests\UpdateInventoryRequest;
use App\Modules\Inventory\Resources\InventoryResource;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Product\Services\ProductService;
use App\Support\Http\ApiResponse;

class InventoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly ProductService $productService,
    ) {}

    public function show(int $productId)
    {
        // Confirms the product exists by calling Product's own service —
        // never a raw query against Product's table — per the cross-module
        // boundary rule. product_id has no FK into products, so nothing
        // stops this route being hit with an id that was never a real
        // product.
        if (! $this->productService->exists($productId)) {
            return $this->fail('Product not found.', 404);
        }

        return $this->ok(new InventoryResource($this->inventoryService->getForProduct($productId)));
    }

    public function update(UpdateInventoryRequest $request, int $productId)
    {
        if (! $this->productService->exists($productId)) {
            return $this->fail('Product not found.', 404);
        }

        $inventory = $this->inventoryService->update($productId, $request->validated());

        return $this->ok(new InventoryResource($inventory));
    }
}
