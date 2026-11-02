<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Xplogent call asked for a vacancy email (send-job-details). The unique index
 * makes a repeated request for the same call, candidate and vacancy a no-op, even when
 * two arrive at once; MySQL/MariaDB allow any number of NULLs, so the CRM's own emails
 * (no call) are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sent_emails') || Schema::hasColumn('sent_emails', 'xplogent_call_id')) {
            return;
        }
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->unsignedBigInteger('xplogent_call_id')->nullable()->after('sale_id');
            $table->unique(['xplogent_call_id', 'applicant_id', 'sale_id'], 'sent_emails_xplogent_call_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sent_emails', 'xplogent_call_id')) {
            Schema::table('sent_emails', function (Blueprint $table) {
                $table->dropUnique('sent_emails_xplogent_call_unique');
                $table->dropColumn('xplogent_call_id');
            });
        }
    }
};
