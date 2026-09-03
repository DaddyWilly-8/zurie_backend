<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Services\CategoryService;
use App\Modules\Product\Services\ProductService;

/**
 * Thin orchestrator, not a data owner — Dashboard has no tables of its own
 * (see zurie-backend-implementation-spec.md §10). Every stat is fetched by
 * calling a single-purpose method on the module that actually owns that
 * data, never a raw query against another module's table. Adding a new
 * stat later means adding one new small method to the module that owns it,
 * then one new line in overview() below — nothing existing here has to
 * change. This is the "one endpoint, many small orchestrated methods"
 * approach chosen over one endpoint per stat — see §17's decision log.
 *
 * The recent* fields return raw model collections, not formatted resource
 * arrays — DashboardController does that wrapping (with the exact same
 * Resource class each owning module's own list endpoint uses), same
 * separation of concerns as every other controller/service pair in this
 * app: services return models, controllers format them for HTTP.
 */
class DashboardService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly CategoryService $categoryService,
        private readonly InventoryService $inventoryService,
        private readonly OrderService $orderService,
        private readonly CustomerService $customerService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'totalProducts' => $this->productService->countTotal(),
            'productsInStock' => $this->inventoryService->countInStock(),
            'productsOutOfStock' => $this->inventoryService->countOutOfStock(),
            'totalCategories' => $this->categoryService->countTotal(),
            'newOrders' => $this->orderService->countNew(),
            'recentOrders' => $this->orderService->recent(),
            'recentProducts' => $this->productService->recent(),
            'recentCustomers' => $this->customerService->recent(),
        ];
    }
}
