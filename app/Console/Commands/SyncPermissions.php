<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * php artisan crm:sync-permissions — after an update, create the permissions the new code checks
 * and give them to the super_admin role (which a fresh install gives every permission).
 *
 * Without it, a permission added later (for example xplogent-agent-call, the "Agent Call (AI)"
 * menu) exists on an upgraded server but nobody has it, so the feature stays hidden. Only adds:
 * other roles and every user's roles are left exactly as they are. Safe to run on every deploy.
 */
class SyncPermissions extends Command
{
    protected $signature = 'crm:sync-permissions {--dry-run : Show what would change, change nothing}';

    protected $description = 'Create missing permissions and give the super_admin role all permissions';

    public function handle(PermissionRegistrar $registrar): int
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles')) {
            $this->error('The permission tables are missing: run php artisan migrate first.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $existing = Permission::where('guard_name', 'web')->pluck('name')->all();
        $missing = array_values(array_diff(PermissionsTableSeeder::names(), $existing));
        if (!$dry) {
            foreach ($missing as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }
        $this->line(($dry ? 'Would create ' : 'Created ') . count($missing) . ' missing permission(s)'
            . ($missing ? ': ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ', …' : '') : '.'));

        $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->first();
        if (!$role) {
            $this->warn('No super_admin role here; nothing granted. Give the new permissions under Roles & Permissions.');
        } else {
            $have = $role->permissions()->pluck('name')->all();
            $grant = $dry
                ? array_values(array_diff(array_unique(array_merge($existing, $missing)), $have))
                : Permission::where('guard_name', 'web')->whereNotIn('name', $have)->pluck('name')->all();
            if (!$dry && $grant) {
                $role->givePermissionTo($grant);       // adds only; nothing is taken away
            }
            $this->line(($dry ? 'Would give ' : 'Gave ') . 'super_admin ' . count($grant) . ' permission(s)'
                . ($grant ? ': ' . implode(', ', array_slice($grant, 0, 10)) . (count($grant) > 10 ? ', …' : '') : '.'));
        }

        if (!$dry) {
            $registrar->forgetCachedPermissions();
        }
        $this->info($dry ? 'Dry run: nothing changed.' : 'Permissions are in sync.');

        return self::SUCCESS;
    }
}
