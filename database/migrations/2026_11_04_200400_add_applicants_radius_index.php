<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The radius search (screen, queue, exports) filters active applicants by job title and a
 * latitude/longitude box before the haversine (audit P-01); this index serves that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('applicants') && !Schema::hasIndex('applicants', 'idx_applicants_radius')) {
            Schema::table('applicants', fn (Blueprint $t) => $t->index(['status', 'job_title_id', 'lat', 'lng'], 'idx_applicants_radius'));
        }
    }

    public function down(): void
    {
        \App\Support\SchemaIndex::dropIfExists('applicants', 'idx_applicants_radius');
    }
};
