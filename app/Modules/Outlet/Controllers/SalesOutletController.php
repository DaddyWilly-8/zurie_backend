<?php

namespace App\Modules\Outlet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Outlet\Requests\StoreSalesOutletRequest;
use App\Modules\Outlet\Resources\SalesOutletResource;
use App\Modules\Outlet\Services\OutletService;
use App\Support\Http\ApiResponse;

class SalesOutletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OutletService $outletService) {}

    public function index()
    {
        return $this->ok(SalesOutletResource::collection($this->outletService->allActive()));
    }

    public function store(StoreSalesOutletRequest $request)
    {
        $outlet = $this->outletService->create($request->validated());

        return $this->created(new SalesOutletResource($outlet));
    }
}
