<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Requests\AssignRolePermissionRequest;
use App\Modules\Auth\Requests\StoreRoleRequest;
use App\Modules\Auth\Requests\UpdateRolePermissionsRequest;
use App\Modules\Auth\Resources\RoleResource;
use App\Modules\Auth\Services\RoleService;
use App\Support\Http\ApiResponse;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleService $roleService) {}

    /**
     * GET /admin/roles — the full role catalog, each with its permissions.
     * Not paginated (small, fixed-ish set) — same "plain array under data,
     * no meta" shape GET /faq already uses.
     */
    public function index()
    {
        return $this->ok(RoleResource::collection($this->roleService->listAll()));
    }

    public function store(StoreRoleRequest $request)
    {
        $role = $this->roleService->create($request->validated());

        return $this->created(new RoleResource($role));
    }

    public function assignPermission(AssignRolePermissionRequest $request, Role $role)
    {
        $role = $this->roleService->assignPermission(
            $role,
            (int) $request->validated()['permissionId'],
            $request->user(),
        );

        return $this->ok(new RoleResource($role));
    }

    /**
     * PATCH /admin/roles/{role} — full replace of a role's permissions,
     * same pattern as PATCH /admin/users/{user} for a user's roles.
     */
    public function update(UpdateRolePermissionsRequest $request, Role $role)
    {
        $role = $this->roleService->syncPermissions(
            $role,
            $request->validated()['permissionIds'],
            $request->user(),
        );

        return $this->ok(new RoleResource($role));
    }
}
