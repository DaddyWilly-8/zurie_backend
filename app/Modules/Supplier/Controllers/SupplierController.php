<?php

namespace App\Modules\Supplier\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Supplier\Requests\StoreSupplierRequest;
use App\Modules\Supplier\Resources\SupplierResource;
use App\Modules\Supplier\Services\SupplierService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SupplierService $supplierService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $suppliers = $this->supplierService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            SupplierResource::collection($suppliers->items()),
            ['count' => $suppliers->total(), 'page' => $suppliers->currentPage(), 'pageSize' => $suppliers->perPage()]
        );
    }

    public function store(StoreSupplierRequest $request)
    {
        $supplier = $this->supplierService->create($request->validated());

        return $this->created(new SupplierResource($supplier));
    }

    public function show(int $id)
    {
        return $this->ok(new SupplierResource($this->supplierService->findOrFail($id)));
    }
}
