<?php

namespace App\Modules\Target\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Target\Requests\StoreTargetRequest;
use App\Modules\Target\Resources\TargetResource;
use App\Modules\Target\Services\TargetService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class TargetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TargetService $targetService) {}

    public function index()
    {
        return $this->ok(TargetResource::collection($this->targetService->all()));
    }

    /**
     * Upsert — setting a target for a period that already has one replaces
     * it rather than creating a duplicate (see TargetService::create()'s
     * updateOrCreate()).
     */
    public function store(StoreTargetRequest $request)
    {
        $target = $this->targetService->create($request->validated());

        return $this->created(new TargetResource($target));
    }

    /**
     * GET /admin/targets/achievement?period=YYYY-MM — defaults to the
     * current month if omitted.
     */
    public function achievement(Request $request)
    {
        return $this->ok($this->targetService->achievementFor($request->query('period')));
    }
}
