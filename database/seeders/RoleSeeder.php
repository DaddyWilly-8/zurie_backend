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
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $name => $description) {
            Role::firstOrCreate(['name' => $name], ['description' => $description]);
        }

        // "admin" holds every permission for now — split this out per-role
        // once staff/super_admin need a narrower or wider set than admin.
        $admin = Role::where('name', 'admin')->firstOrFail();
        $admin->permissions()->sync(Permission::pluck('id'));
    }
}
