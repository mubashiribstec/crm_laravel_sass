<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Xplogent call summary (call_id is the idempotency key). The full
 * payload, transcript included, lives here and never in applicant notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('xplogent_call_events')) {
            return;
        }
        Schema::create('xplogent_call_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('call_id')->unique();
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->unsignedBigInteger('call_log_id')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xplogent_call_events');
    }
};
