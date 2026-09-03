<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Resources\CustomerResource;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Order\Resources\OrderListResource;
use App\Modules\Product\Resources\AdminProductResource;
use App\Support\Http\ApiResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $dashboardService) {}

    /**
     * GET /admin/dashboard-overview — single aggregated response, not one
     * endpoint per stat. See DashboardService::overview() for why.
     *
     * The recent* lists are formatted here with the exact same Resource
     * class each field's owning list endpoint already uses (OrderListResource
     * for GET /admin/orders, AdminProductResource for GET /admin/products,
     * CustomerResource for GET /admin/customers) — a dashboard row looks
     * identical to the equivalent row on that module's own admin page,
     * per your call. DashboardService only ever hands back raw models.
     */
    public function overview()
    {
        $overview = $this->dashboardService->overview();

        $overview['recentOrders'] = OrderListResource::collection($overview['recentOrders']);
        $overview['recentProducts'] = AdminProductResource::collection($overview['recentProducts']);
        $overview['recentCustomers'] = CustomerResource::collection($overview['recentCustomers']);

        return $this->ok($overview);
    }
}
