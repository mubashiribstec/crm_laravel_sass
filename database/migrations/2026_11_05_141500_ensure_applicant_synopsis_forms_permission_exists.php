<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_PERMISSION = 'applicant-show-draft-file';
    private const NEW_PERMISSION = 'applicant-show-synopsis-forms';

    public function up(): void
    {
        $permissionTable = config('permission.table_names.permissions', 'permissions');
        $guardName = config('auth.defaults.guard', 'web');

        DB::transaction(function () use ($permissionTable, $guardName) {
            $permission = DB::table($permissionTable)
                ->where('name', self::NEW_PERMISSION)
                ->where('guard_name', $guardName)
                ->first();

            if (!$permission) {
                $oldPermission = DB::table($permissionTable)
                    ->where('name', self::OLD_PERMISSION)
                    ->where('guard_name', $guardName)
                    ->first();

                if ($oldPermission) {
                    DB::table($permissionTable)
                        ->where('id', $oldPermission->id)
                        ->update(['name' => self::NEW_PERMISSION]);
                } else {
                    DB::table($permissionTable)->insert([
                        'name' => self::NEW_PERMISSION,
                        'guard_name' => $guardName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Keep this permission because it may have been assigned after migration.
    }
};
