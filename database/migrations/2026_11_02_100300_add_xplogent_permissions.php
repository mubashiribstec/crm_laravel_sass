<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Permissions for the "Agent call" page (Xplogent). Also listed in
 * PermissionsTableSeeder; created here so existing installs get them on migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        foreach (['xplogent-agent-call', 'xplogent-view-transcript'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Permission::whereIn('name', ['xplogent-agent-call', 'xplogent-view-transcript'])->delete();
        }
    }
};
