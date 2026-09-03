<?php

namespace App\Modules\Customer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Resources\CustomerResource;
use App\Modules\Customer\Services\CustomerService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CustomerService $customerService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $customers = $this->customerService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            CustomerResource::collection($customers->items()),
            [
                'count' => $customers->total(),
                'page' => $customers->currentPage(),
                'pageSize' => $customers->perPage(),
            ]
        );
    }

    public function show(int $id)
    {
        return $this->ok(new CustomerResource($this->customerService->findForAdmin($id)));
    }
}
