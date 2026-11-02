<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xplogent's dial locks carry a per-call lease token: only the same token may
 * renew or release a lock, so one AI worker can never free another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dial_locks') && !Schema::hasColumn('dial_locks', 'lock_token')) {
            Schema::table('dial_locks', function (Blueprint $table) {
                $table->string('lock_token', 64)->nullable()->after('applicant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dial_locks') && Schema::hasColumn('dial_locks', 'lock_token')) {
            Schema::table('dial_locks', function (Blueprint $table) {
                $table->dropColumn('lock_token');
            });
        }
    }
};
