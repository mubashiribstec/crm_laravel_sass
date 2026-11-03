<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            if (!Schema::hasColumn('offices', 'office_type')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->string('office_type', 50)->nullable()->after('office_name');
                $table->index('office_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('office_type');
        });
    }
};

