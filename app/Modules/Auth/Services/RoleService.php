<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RoleService
{
    /**
     * The full role catalog, each with its permissions eager-loaded — a
     * small, fixed-ish set (super_admin/admin/staff, seeded via
     * RoleSeeder, occasionally added to via POST /roles), so a plain
     * unpaginated list rather than a paginated one. Needed by the
     * frontend for a role picker when assigning roles to a user
     * (POST /users/{user}/roles, PATCH /admin/users/{id}) and to show
     * each role's current permission set when managing it.
     */
    public function listAll(): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Role
    {
        return Role::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function assignPermission(Role $role, int $permissionId): Role
    {
        DB::transaction(function () use ($role, $permissionId): void {
            $role->permissions()->syncWithoutDetaching([$permissionId]);
        });

        $role->load('permissions');

        // Pivot sync — never fires Role's own LogsActivity trait, same
        // reasoning as UserService::assignRole().
        $permissionKey = $role->permissions->firstWhere('id', $permissionId)?->key ?? "permission #{$permissionId}";
        activity('auth')
            ->performedOn($role)
            ->event('updated')
            ->log("Permission '{$permissionKey}' assigned to role '{$role->name}'");

        return $role;
    }

    /**
     * PATCH /admin/roles/{role} — full replace, unlike assignPermission()'s
     * additive syncWithoutDetaching(). This is the only way to revoke a
     * permission from a role via the API; an empty array is valid and
     * revokes everything.
     *
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        DB::transaction(function () use ($role, $permissionIds): void {
            $role->permissions()->sync($permissionIds);
        });

        $role->load('permissions');

        // Pivot sync — never fires Role's own LogsActivity trait, same
        // reasoning as assignPermission()/UserService::syncRoles().
        $permissionKeys = $role->permissions->pluck('key')->implode(', ') ?: 'no permissions';
        activity('auth')
            ->performedOn($role)
            ->event('updated')
            ->log("Permissions for role '{$role->name}' set to: {$permissionKeys}");

        return $role;
    }
}
