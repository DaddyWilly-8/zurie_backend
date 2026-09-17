<?php

namespace App\Modules\Outlet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Outlet\Models\SalesOutlet;
use App\Modules\Outlet\Requests\StoreSalesOutletRequest;
use App\Modules\Outlet\Requests\UpdateSalesOutletRequest;
use App\Modules\Outlet\Resources\SalesOutletResource;
use App\Modules\Outlet\Services\OutletService;
use App\Support\Http\ApiResponse;

class SalesOutletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OutletService $outletService) {}

    /**
     * Every outlet, active or not — see OutletService::all()'s docblock
     * for why this differs from allActive() (used by Order/POS).
     */
    public function index()
    {
        return $this->ok(SalesOutletResource::collection($this->outletService->all()));
    }

    public function show(SalesOutlet $outlet)
    {
        return $this->ok(new SalesOutletResource($outlet));
    }

    public function store(StoreSalesOutletRequest $request)
    {
        $outlet = $this->outletService->create($request->validated());

        return $this->created(new SalesOutletResource($outlet));
    }

    /**
     * PATCH /admin/outlets/{outlet} — field edits and the isActive toggle
     * ("delete" in the admin UI) in one call, same shape as
     * CostCenterController::update()/SupplierController::update().
     */
    public function update(UpdateSalesOutletRequest $request, SalesOutlet $outlet)
    {
        $data = $request->validated();

        $outlet = $this->outletService->update($outlet, $data);

        if (array_key_exists('isActive', $data)) {
            $outlet = $this->outletService->setActive($outlet, $data['isActive']);
        }

        return $this->ok(new SalesOutletResource($outlet));
    }
}
