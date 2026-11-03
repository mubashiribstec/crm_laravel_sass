<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Horsefly\User;
use Carbon\Carbon;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Idempotent: a second db:seed used to fail on roles_name_guard_name_unique.
        foreach (['super_admin', 'admin', 'crm', 'sales', 'quality', 'agent'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['type' => $name]);
        }
        $superAdminRole = Role::where('name', 'super_admin')->where('guard_name', 'web')->firstOrFail();

        // Fetch all permissions
        $allPermissions = Permission::all();

        // Assign all permissions to super_admin role
        $superAdminRole->syncPermissions($allPermissions);

        // The seeded admin (by email, not "whoever has id 1").
        $user = User::where('email', 'admin@gmail.com')->first();
        if ($user && !$user->hasRole('super_admin')) {
            $user->assignRole('super_admin'); // This updates model_has_roles
        }
    }
}
