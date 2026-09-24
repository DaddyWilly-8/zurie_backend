<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Role set matches the `role` enum in zurie-api-contract.md:
     * super_admin | admin | staff.
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        return [
            'super_admin' => 'Full system access',
            'admin' => 'Day-to-day store administration',
            'staff' => 'Limited operational access',
            // No 'customer' role here anymore — the customer/staff split
            // moved storefront logins to their own `customer_accounts`
            // table and Auth guard (App\Modules\Auth\Models\CustomerAccount),
            // which never goes through the RBAC system at all. A
            // pre-split 'customer' Role row may still exist in an older
            // database (this seeder never deletes rows), but it's inert —
            // nothing assigns or checks it anymore.
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $name => $description) {
            Role::firstOrCreate(['name' => $name], ['description' => $description]);
        }

        // "super_admin" ("Full system access") and "admin" both hold every
        // permission for now — split this out per-role once they need
        // different sets. Without super_admin here, a fresh install left
        // super_admin users able to log in but 403'd on every admin page.
        foreach (['super_admin', 'admin'] as $roleName) {
            Role::where('name', $roleName)->firstOrFail()->permissions()->sync(Permission::pluck('id'));
        }
    }
}
