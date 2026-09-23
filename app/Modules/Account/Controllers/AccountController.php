<?php

namespace App\Modules\Account\Controllers;

use App\Modules\Customer\Resources\CustomerResource;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Order\Resources\OrderResource;
use App\Modules\Order\Services\OrderService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints for the logged-in customer's own profile and
 * order history — customer-only ('customer' guard) since the customer/
 * staff split. Always scoped to $request->user('customer')->stakeholder_id,
 * never an id read from the request — a customer can never view another
 * customer's data through this controller.
 */
class AccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CustomerService $customerService,
        private readonly OrderService $orderService,
    ) {}

    /**
     * Returns null data if the account has never linked a Customer
     * record (e.g. a Google sign-up that hasn't completed its profile
     * yet) — not a 404, since "no profile yet" is an expected state, not
     * an error.
     */
    public function profile(Request $request)
    {
        $customer = $this->findCustomer($request);

        return $this->ok($customer ? new CustomerResource($customer) : null);
    }

    public function orders(Request $request)
    {
        $customer = $this->findCustomer($request);

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

    private function findCustomer(Request $request)
    {
        $stakeholderId = $request->user('customer')->stakeholder_id;

        return $stakeholderId !== null ? $this->customerService->findById($stakeholderId) : null;
    }
}
