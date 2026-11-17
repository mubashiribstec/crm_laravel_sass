<?php

namespace Tests\Feature\Deploy;

use Database\Seeders\AllowedIpsSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * Upgraded servers: a permission added by a later release (xplogent-agent-call, the "Agent
 * Call (AI)" menu) reached nobody, because only a fresh seed gives super_admin everything.
 * crm:sync-permissions (run by deploy/update.sh) closes that gap without touching anyone else.
 */
class SyncPermissionsTest extends XplogentTestCase
{
    public function test_it_creates_missing_permissions_and_gives_super_admin_all_of_them(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $super = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $sales = Role::create(['name' => 'sales', 'guard_name' => 'web']);
        $sales->givePermissionTo(Permission::firstOrCreate(['name' => 'sale-index', 'guard_name' => 'web']));
        $boss = $this->staff('Old Admin');
        $boss->assignRole($super);
        $clerk = $this->staff('Sales Clerk');
        $clerk->assignRole($sales);
        Permission::where('name', 'dashboard')->delete();                          // as on an old server
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($boss->fresh()->can('xplogent-agent-call'));

        $this->artisan('crm:sync-permissions', ['--dry-run' => true])
            ->expectsOutputToContain('Would create 1 missing permission(s): dashboard')
            ->expectsOutputToContain('Dry run: nothing changed.')->assertSuccessful();
        $this->assertFalse(Permission::where('name', 'dashboard')->exists());

        $this->artisan('crm:sync-permissions')->expectsOutputToContain('Permissions are in sync.')->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Permission::where('name', 'dashboard')->exists());
        $this->assertSame(Permission::count(), $super->fresh()->permissions()->count());
        $this->assertTrue($boss->fresh()->can('xplogent-agent-call'));
        $this->assertEqualsCanonicalizing(['sale-index'], $sales->fresh()->permissions()->pluck('name')->all());
        $this->assertFalse($clerk->fresh()->can('xplogent-agent-call'));
        $this->assertSame(['super_admin'], $boss->fresh()->getRoleNames()->all());

        // A second run changes nothing.
        $this->artisan('crm:sync-permissions')
            ->expectsOutputToContain('Created 0 missing permission(s).')
            ->expectsOutputToContain('Gave super_admin 0 permission(s).')->assertSuccessful();
    }

    public function test_without_a_super_admin_role_it_only_creates_permissions(): void
    {
        $this->artisan('crm:sync-permissions')
            ->expectsOutputToContain('No super_admin role here')->assertSuccessful();
        $this->assertSame(count(PermissionsTableSeeder::names()),
            Permission::whereIn('name', PermissionsTableSeeder::names())->count());
        $this->assertFalse(Role::where('name', 'super_admin')->exists());
    }

    public function test_allowed_ip_prefixes_come_from_env_and_are_only_added(): void
    {
        DB::table('allowed_ips')->insert(['ip_prefix' => '10.9.8']);
        // env() reads $_ENV / $_SERVER (filled from .env) before getenv(): set all three.
        $key = 'CRM_ALLOWED_IP_PREFIXES';
        $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $set = function (?string $v) use ($key) {
            if ($v === null) { unset($_ENV[$key], $_SERVER[$key]); putenv($key); }
            else { $_ENV[$key] = $_SERVER[$key] = $v; putenv("{$key}={$v}"); }
        };
        $set('172.16.4, 10.0.5.* ,bad,1.2.3.4,127.0.0');
        try {
            $this->assertSame(['172.16.4', '10.0.5', '127.0.0'], AllowedIpsSeeder::prefixes());
            $this->seed(AllowedIpsSeeder::class);
            $this->seed(AllowedIpsSeeder::class);                                // twice: no duplicates
        } finally {
            $set(null);
        }
        $this->assertEqualsCanonicalizing(['10.9.8', '172.16.4', '10.0.5', '127.0.0'],
            DB::table('allowed_ips')->pluck('ip_prefix')->all());
        $this->assertSame(['192.168.110', '127.0.0'], AllowedIpsSeeder::prefixes()); // the default
        [$e, $sv, $g] = $saved;                                                    // as .env had it
        if ($e !== null) { $_ENV[$key] = $e; }
        if ($sv !== null) { $_SERVER[$key] = $sv; }
        if ($g !== false) { putenv("{$key}={$g}"); }
    }
}
