<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Resources\PermissionResource;
use App\Modules\Auth\Services\PermissionService;
use App\Support\Http\ApiResponse;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PermissionService $permissionService) {}

    /**
     * GET /admin/permissions — the full permission catalog. Not paginated
     * (small, fixed-ish set, seeded via PermissionSeeder) — same "plain
     * array under data, no meta" shape GET /faq already uses.
     */
    public function index()
    {
        return $this->ok(PermissionResource::collection($this->permissionService->listAll()));
    }
}
