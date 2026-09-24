<?php

namespace Tests\Feature\CustomerAuth;

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pre-split storefront signups were `users` rows with the 'customer' role
 * and kept appearing in Admin Users after the split. The
 * move_legacy_customer_users_to_customer_accounts migration moves them to
 * `customer_accounts`; these tests recreate that legacy state and run it.
 */
class LegacyCustomerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private int $customerRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        // RoleSeeder no longer creates it; legacy databases still have it.
        $this->customerRoleId = Role::create(['name' => 'customer', 'description' => 'legacy'])->id;
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_25_100501_move_legacy_customer_users_to_customer_accounts.php');
        $migration->up();
    }

    private function legacyCustomer(string $email, string $password = 'secret123'): User
    {
        $user = User::create(['name' => 'Legacy '.$email, 'email' => $email, 'password' => Hash::make($password)]);
        $user->roles()->attach($this->customerRoleId);

        return $user;
    }

    public function test_customer_only_user_becomes_a_customer_account_that_keeps_its_password(): void
    {
        $user = $this->legacyCustomer('legacy@example.com');
        $stakeholderId = DB::table('stakeholders')->insertGetId([
            'name' => 'Legacy', 'phone' => '0712000001', 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'type' => 'order', 'message' => 'm', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $account = CustomerAccount::where('email', 'legacy@example.com')->firstOrFail();
        $this->assertSame($stakeholderId, $account->stakeholder_id);
        $this->assertTrue(Hash::check('secret123', $account->password));
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => CustomerAccount::class, 'notifiable_id' => $account->id,
        ]);
        $this->assertDatabaseMissing('roles', ['name' => 'customer']);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/v1/customer/auth/login', ['email' => 'legacy@example.com', 'password' => 'secret123'])
            ->assertOk();
    }

    public function test_reuses_an_existing_customer_account_with_the_same_email(): void
    {
        $user = $this->legacyCustomer('google@example.com');
        $existing = CustomerAccount::create(['name' => 'G', 'email' => 'google@example.com', 'password' => 'x']);

        $this->runMigration();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(1, CustomerAccount::where('email', 'google@example.com')->count());
        $this->assertSame($existing->id, CustomerAccount::where('email', 'google@example.com')->value('id'));
    }

    public function test_staff_user_with_customer_role_only_loses_that_role(): void
    {
        $staff = $this->legacyCustomer('staff@example.com');
        $staff->roles()->attach(Role::where('name', 'admin')->value('id'));

        $this->runMigration();

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertSame(['admin'], $staff->roles()->pluck('name')->all());
        $this->assertDatabaseMissing('customer_accounts', ['email' => 'staff@example.com']);
    }

    public function test_is_idempotent(): void
    {
        $this->legacyCustomer('twice@example.com');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(1, CustomerAccount::where('email', 'twice@example.com')->count());
    }
}
