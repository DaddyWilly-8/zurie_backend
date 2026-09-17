<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Requests\StoreCostCenterRequest;
use App\Modules\Finance\Requests\UpdateCostCenterRequest;
use App\Modules\Finance\Resources\CostCenterResource;
use App\Modules\Finance\Services\CostCenterService;
use App\Support\Http\ApiResponse;

class CostCenterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CostCenterService $costCenterService) {}

    public function index()
    {
        return $this->ok(CostCenterResource::collection($this->costCenterService->all()));
    }

    public function show(CostCenter $costCenter)
    {
        return $this->ok(new CostCenterResource($costCenter));
    }

    public function store(StoreCostCenterRequest $request)
    {
        $costCenter = $this->costCenterService->create($request->validated());

        return $this->created(new CostCenterResource($costCenter));
    }

    /**
     * PATCH /admin/finance/cost-centers/{costCenter} — a single endpoint
     * for both field edits and the isActive toggle ("delete" in the admin
     * UI is this same call with isActive: false), rather than a separate
     * route per concern — see Supplier/Outlet controllers for the same
     * shape.
     */
    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter)
    {
        $data = $request->validated();

        $costCenter = $this->costCenterService->update($costCenter, $data);

        if (array_key_exists('isActive', $data)) {
            $costCenter = $this->costCenterService->setActive($costCenter, $data['isActive']);
        }

        return $this->ok(new CostCenterResource($costCenter));
    }
}
