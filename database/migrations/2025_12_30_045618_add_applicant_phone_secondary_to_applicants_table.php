<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            if (!Schema::hasColumn('applicants', 'applicant_phone_secondary')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->string('applicant_phone_secondary', 50)
                    ->nullable()
                    ->after('applicant_phone')
                    ->index('idx_applicant_phone_secondary');
            }
        });
    }

    public function down(): void
    {
        \App\Support\SchemaIndex::dropIfExists('applicants', 'idx_applicant_phone_secondary');
        if (Schema::hasColumn('applicants', 'applicant_phone_secondary')) {
            Schema::table('applicants', fn (Blueprint $table) => $table->dropColumn('applicant_phone_secondary'));
        }
    }
};

