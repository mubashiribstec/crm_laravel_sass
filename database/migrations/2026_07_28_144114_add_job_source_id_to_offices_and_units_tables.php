<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            if (!Schema::hasColumn('offices', 'job_source_id')) {   // guard (M-08)
                $table->unsignedBigInteger('job_source_id')->nullable()->after('user_id');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'job_source_id')) {   // guard (M-08)
                $table->unsignedBigInteger('job_source_id')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('job_source_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('job_source_id');
        });
    }
};