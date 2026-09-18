<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleService
{
    /**
     * Privilege-escalation guard — found during a security review: any
     * account holding `user_manage` could grant its own role (or any
     * role) an arbitrary permission via this endpoint, with nothing
     * stopping it from granting permissions it doesn't itself hold. A
     * "ceiling" check closes this: an admin can only ever grant a role a
     * permission they already possess themselves — the same principle
     * every mature RBAC system enforces (you can't delegate authority
     * you don't have). This does mean the seeded super_admin/admin roles
     * (which hold every permission — see RoleSeeder) are the only
     * accounts that can grant every permission; a narrower admin can
     * only extend a role within their own permission set.
     */
    private function assertCanGrantPermission(User $actingUser, int $permissionId): void
    {
        $permissionKey = Permission::query()->findOrFail($permissionId)->key;

        if (! $actingUser->hasPermission($permissionKey)) {
            throw ValidationException::withMessages([
                'permissionId' => "You cannot grant the '{$permissionKey}' permission because you don't hold it yourself.",
            ]);
        }
    }
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

    public function assignPermission(Role $role, int $permissionId, User $actingUser): Role
    {
        $this->assertCanGrantPermission($actingUser, $permissionId);

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
     * Only the *newly added* permission ids are ceiling-checked against
     * the acting user's own permissions (see assertCanGrantPermission())
     * — removing a permission, or keeping one already on the role, is
     * never an escalation, so those pass through unchecked even if the
     * acting user doesn't hold them.
     *
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds, User $actingUser): Role
    {
        $newlyAddedIds = array_diff($permissionIds, $role->permissions()->pluck('permissions.id')->all());
        foreach ($newlyAddedIds as $permissionId) {
            $this->assertCanGrantPermission($actingUser, $permissionId);
        }

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
