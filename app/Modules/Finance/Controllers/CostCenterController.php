<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Requests\StoreCostCenterRequest;
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

    public function store(StoreCostCenterRequest $request)
    {
        $costCenter = $this->costCenterService->create($request->validated());

        return $this->created(new CostCenterResource($costCenter));
    }
}
