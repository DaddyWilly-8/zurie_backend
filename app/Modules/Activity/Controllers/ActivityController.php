<?php

namespace App\Modules\Activity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Resources\ActivityResource;
use App\Modules\Activity\Services\ActivityService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ActivityService $activityService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $activities = $this->activityService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            ActivityResource::collection($activities->items()),
            [
                'count' => $activities->total(),
                'page' => $activities->currentPage(),
                'pageSize' => $activities->perPage(),
            ]
        );
    }
}
