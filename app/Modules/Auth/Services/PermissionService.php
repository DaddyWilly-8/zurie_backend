<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class PermissionService
{
    /**
     * The full permission catalog — small, fixed-ish set (seeded via
     * PermissionSeeder), so a plain unpaginated list, same treatment as
     * RoleService::listAll(). Needed by the frontend to populate a
     * permission picker when assigning permissions to a role
     * (POST /roles/{role}/permissions) — there was previously no way to
     * see what permission keys even exist without reading the seeder
     * source.
     */
    public function listAll(): Collection
    {
        return Permission::query()->orderBy('key')->get();
    }
}
