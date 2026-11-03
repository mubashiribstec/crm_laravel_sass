<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call_logs (Horsefly\CallLog) had no migration in the repository: the table
 * was created by hand on production. Create it where it is missing so fresh
 * installs and tests have it; an existing table is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('call_logs')) {
            return;
        }
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('caller_number', 30)->nullable();
            $table->string('caller_name', 255)->nullable();
            $table->string('direction', 20)->default('outbound');   // inbound | outbound | missed
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('sip_call_id', 255)->nullable()->index();
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->string('source', 20)->nullable();               // browser | desktop | xplogent
            $table->timestamp('called_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never drop a table this migration may not have created.
    }
};
