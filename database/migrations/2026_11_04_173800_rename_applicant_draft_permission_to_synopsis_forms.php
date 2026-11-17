<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_PERMISSION = 'applicant-show-draft-file';
    private const NEW_PERMISSION = 'applicant-show-synopsis-forms';

    public function up(): void
    {
        $this->renamePermission(self::OLD_PERMISSION, self::NEW_PERMISSION);
    }

    public function down(): void
    {
        $this->renamePermission(self::NEW_PERMISSION, self::OLD_PERMISSION);
    }

    private function renamePermission(string $from, string $to): void
    {
        $permissionTable = config('permission.table_names.permissions', 'permissions');
        $permissionIdColumn = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $guardName = config('auth.defaults.guard', 'web');

        DB::transaction(function () use ($permissionTable, $permissionIdColumn, $guardName, $from, $to) {
            $oldPermission = DB::table($permissionTable)
                ->where('name', $from)
                ->where('guard_name', $guardName)
                ->first();

            if (!$oldPermission) {
                return;
            }

            $newPermission = DB::table($permissionTable)
                ->where('name', $to)
                ->where('guard_name', $guardName)
                ->first();

            if (!$newPermission) {
                DB::table($permissionTable)
                    ->where('id', $oldPermission->id)
                    ->update(['name' => $to]);

                $this->clearPermissionCache();

                return;
            }

            foreach ([
                config('permission.table_names.role_has_permissions', 'role_has_permissions'),
                config('permission.table_names.model_has_permissions', 'model_has_permissions'),
            ] as $pivotTable) {
                $assignments = DB::table($pivotTable)
                    ->where($permissionIdColumn, $oldPermission->id)
                    ->get()
                    ->map(function ($assignment) use ($permissionIdColumn, $newPermission) {
                        $row = (array) $assignment;
                        $row[$permissionIdColumn] = $newPermission->id;

                        return $row;
                    })
                    ->all();

                if ($assignments !== []) {
                    DB::table($pivotTable)->insertOrIgnore($assignments);
                }

                DB::table($pivotTable)
                    ->where($permissionIdColumn, $oldPermission->id)
                    ->delete();
            }

            DB::table($permissionTable)->where('id', $oldPermission->id)->delete();
            $this->clearPermissionCache();
        });
    }

    private function clearPermissionCache(): void
    {
        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
