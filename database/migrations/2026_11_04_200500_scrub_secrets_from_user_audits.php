<?php

use App\Observers\UserObserver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until audit N-11, UserObserver copied every changed column into the audit row, so a password
 * change stored the new bcrypt hash (and the remember token, and the encrypted SIP password) in
 * audits.data, and the user activity log showed it. This replaces those values with "(changed)"
 * in the existing rows. Nothing else in the rows changes; safe to run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audits')) {
            return;
        }

        DB::table('audits')
            ->where('auditable_type', 'Horsefly\User')
            // data is usually JSON inside a JSON string (the observers pass JSON to an array cast),
            // so the quotes around the names are escaped: match the bare names.
            ->where(fn ($q) => $q->where('data', 'like', '%password%')->orWhere('data', 'like', '%remember_token%'))
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $data = json_decode((string) $row->data, true);
                    $twice = is_string($data);
                    if ($twice) {
                        $data = json_decode($data, true);
                    }
                    if (!is_array($data)) {
                        continue;
                    }
                    $changes = $data['changes_made'] ?? null;
                    if (is_string($changes)) {
                        $changes = json_decode($changes, true);
                    }
                    $dirty = false;
                    if (is_array($changes)) {
                        foreach (UserObserver::SECRET_FIELDS as $field) {
                            if (array_key_exists($field, $changes) && $changes[$field] !== UserObserver::CHANGED) {
                                $changes[$field] = UserObserver::CHANGED;
                                $dirty = true;
                            }
                        }
                        $data['changes_made'] = $changes;
                    }
                    foreach (UserObserver::SECRET_FIELDS as $field) {   // not expected (hidden), but never kept
                        if (array_key_exists($field, $data)) {
                            unset($data[$field]);
                            $dirty = true;
                        }
                    }
                    if ($dirty) {
                        DB::table('audits')->where('id', $row->id)
                            ->update(['data' => $twice ? json_encode(json_encode($data)) : json_encode($data),   // as stored
                                'updated_at' => DB::raw('updated_at')]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The removed values are not kept anywhere, on purpose.
    }
};
