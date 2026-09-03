<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@zurie.local'],
            [
                'name' => 'Local Admin',
                'password' => Hash::make('admin12345'),
            ]
        );

        $admin = Role::where('name', 'admin')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$admin->id]);
    }
}
