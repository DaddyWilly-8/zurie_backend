<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }

    /**
     * Privilege-escalation guard, added during a security review: without
     * this, any account holding `user_manage` could grant itself an
     * arbitrary role (including one with every permission) via this same
     * endpoint. Self-modification of roles is blocked outright — an
     * account managing roles must always be a *different* admin from the
     * one being changed, mirroring the standard "you can't promote
     * yourself" rule most RBAC systems enforce. Role*permission*
     * escalation (a narrower admin granting a role more power than they
     * themselves hold) is closed separately in RoleService, since that's
     * where permissions actually get attached to a role.
     */
    private function assertNotActingOnSelf(User $user, User $actingUser): void
    {
        if ($user->id === $actingUser->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot change your own roles.',
            ]);
        }
    }

    public function assignRole(User $user, int $roleId, User $actingUser): User
    {
        $this->assertNotActingOnSelf($user, $actingUser);

        // sync()/syncWithoutDetaching() issue separate attach/detach queries
        // under the hood with no implicit transaction of their own.
        DB::transaction(function () use ($user, $roleId): void {
            $user->roles()->syncWithoutDetaching([$roleId]);
        });

        $user->load('roles');

        // Pivot syncs never fire Eloquent model events — User's own
        // LogsActivity trait can't see this, so it's logged explicitly here.
        $roleName = $user->roles->firstWhere('id', $roleId)?->name ?? "role #{$roleId}";
        activity('auth')
            ->performedOn($user)
            ->event('updated')
            ->log("Role '{$roleName}' assigned to {$user->email}");

        return $user;
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    public function syncRoles(User $user, array $roleIds, User $actingUser): User
    {
        $this->assertNotActingOnSelf($user, $actingUser);

        DB::transaction(function () use ($user, $roleIds): void {
            $user->roles()->sync($roleIds);
        });

        $user->load('roles');

        $roleNames = $user->roles->pluck('name')->implode(', ') ?: 'no roles';
        activity('auth')
            ->performedOn($user)
            ->event('updated')
            ->log("Roles for {$user->email} set to: {$roleNames}");

        return $user;
    }

    public function paginate(int $page, int $pageSize): LengthAwarePaginator
    {
        return User::query()
            ->with('roles')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}
