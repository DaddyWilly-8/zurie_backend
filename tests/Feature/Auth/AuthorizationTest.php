<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\RoleService;
use App\Modules\Auth\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function narrowAdmin(string ...$permissionKeys): User
    {
        $role = Role::create(['name' => 'narrow-'.uniqid(), 'description' => 'test role']);
        $role->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));

        $user = User::create(['name' => 'Narrow', 'email' => uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->roles()->attach($role->id);

        return $user->load('roles.permissions');
    }

    public function test_a_user_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $narrow = $this->narrowAdmin('user_manage'); // holds user_manage but NOT report_view
        $targetRole = Role::create(['name' => 'target-role', 'description' => 'x']);
        $reportView = Permission::where('key', 'report_view')->firstOrFail();

        $this->expectException(ValidationException::class);

        app(RoleService::class)->assignPermission($targetRole, $reportView->id, $narrow);
    }

    public function test_a_user_can_grant_a_permission_they_do_hold(): void
    {
        $narrow = $this->narrowAdmin('user_manage', 'report_view');
        $targetRole = Role::create(['name' => 'target-role-2', 'description' => 'x']);
        $reportView = Permission::where('key', 'report_view')->firstOrFail();

        app(RoleService::class)->assignPermission($targetRole, $reportView->id, $narrow);

        $this->assertTrue($targetRole->fresh()->permissions->contains('id', $reportView->id));
    }

    public function test_a_user_cannot_change_their_own_roles(): void
    {
        $admin = $this->narrowAdmin('user_manage');
        $anyRole = Role::first();

        $this->expectException(ValidationException::class);

        app(UserService::class)->assignRole($admin, $anyRole->id, $admin);
    }

    public function test_a_user_can_change_another_users_roles(): void
    {
        $admin = $this->narrowAdmin('user_manage');
        $other = User::create(['name' => 'Other', 'email' => uniqid().'@test.local', 'password' => bcrypt('x')]);
        $anyRole = Role::first();

        app(UserService::class)->assignRole($other, $anyRole->id, $admin);

        $this->assertTrue($other->fresh()->roles->contains('id', $anyRole->id));
    }

    public function test_an_unauthenticated_request_to_an_admin_route_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/reports/trial-balance')->assertStatus(401);
    }

    public function test_a_logged_in_user_without_the_permission_is_forbidden(): void
    {
        $user = $this->narrowAdmin('user_manage'); // no report_view

        $this->actingAs($user)
            ->getJson('/api/v1/admin/reports/trial-balance')
            ->assertStatus(403);
    }

    public function test_a_logged_in_user_with_the_permission_is_allowed(): void
    {
        $user = $this->narrowAdmin('report_view');

        $this->actingAs($user)
            ->getJson('/api/v1/admin/reports/trial-balance')
            ->assertStatus(200);
    }

    public function test_admin_role_holds_every_seeded_permission(): void
    {
        $admin = Role::where('name', 'admin')->firstOrFail();

        $this->assertSame(Permission::count(), $admin->permissions()->count());
    }
}
