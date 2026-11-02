<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * call-summary is idempotent on call_id because of this unique index: two deliveries of
 * the same call racing each other can't both write. The table's own migration skips
 * everything when the table already exists, so make sure the index is there.
 *
 * If duplicate call_ids already exist, this stops with a message instead of deleting rows:
 * which copy to keep (each wrote a call log and notes) is a person's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('xplogent_call_events')
            || Schema::hasIndex('xplogent_call_events', ['call_id'], 'unique')) {
            return;
        }
        $duplicates = DB::table('xplogent_call_events')->select('call_id')
            ->groupBy('call_id')->havingRaw('COUNT(*) > 1')->pluck('call_id');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('xplogent_call_events has ' . $duplicates->count() . ' call_id(s) stored more than '
                . 'once (e.g. ' . $duplicates->take(5)->implode(', ') . '). Keep one row per call_id (and check the '
                . 'matching call_logs and notes), then run php artisan migrate again.');
        }
        Schema::table('xplogent_call_events', function (Blueprint $table) {
            $table->unique('call_id');
        });
    }

    public function down(): void
    {
        // Nothing: the index belongs to the table's own migration.
    }
};
