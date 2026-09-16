<?php

namespace App\Modules\Account\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Resources\CustomerResource;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Order\Resources\OrderResource;
use App\Modules\Order\Services\OrderService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints for the authenticated user's own customer profile
 * and order history — deliberately role-agnostic (any authenticated user
 * can call these, not just `customer`-role accounts). Always scoped to
 * `$request->user()->id`, never an id read from the request — a customer
 * can never view another customer's data through this controller.
 */
class AccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CustomerService $customerService,
        private readonly OrderService $orderService,
    ) {}

    /**
     * Returns null data if the authenticated user has never linked a
     * Customer record (e.g. an admin/staff account that never registered
     * as a storefront customer) — not a 404, since "no profile yet" is an
     * expected state, not an error.
     */
    public function profile(Request $request)
    {
        $customer = $this->customerService->findByUserId($request->user()->id);

        return $this->ok($customer ? new CustomerResource($customer) : null);
    }

    public function orders(Request $request)
    {
        $customer = $this->customerService->findByUserId($request->user()->id);

        if ($customer === null) {
            return $this->paginated([], ['count' => 0, 'page' => 1, 'pageSize' => 20]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $orders = $this->orderService->paginateForCustomer($customer->id, $page, $pageSize);

        return $this->paginated(
            OrderResource::collection($orders->items()),
            [
                'count' => $orders->total(),
                'page' => $orders->currentPage(),
                'pageSize' => $orders->perPage(),
            ]
        );
    }
}
