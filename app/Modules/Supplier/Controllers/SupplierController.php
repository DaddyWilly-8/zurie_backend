<?php

namespace App\Modules\Supplier\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Supplier\Requests\StoreSupplierRequest;
use App\Modules\Supplier\Requests\UpdateSupplierRequest;
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

    /**
     * PATCH /admin/suppliers/{id} — field edits and the isActive toggle
     * ("delete" in the admin UI) in one call, same shape as
     * CostCenterController::update()/SalesOutletController::update().
     */
    public function update(UpdateSupplierRequest $request, int $id)
    {
        $supplier = $this->supplierService->findOrFail($id);
        $data = $request->validated();

        $supplier = $this->supplierService->update($supplier, $data);

        if (array_key_exists('isActive', $data)) {
            $supplier = $this->supplierService->setActive($supplier, $data['isActive']);
        }

        return $this->ok(new SupplierResource($supplier));
    }
}
