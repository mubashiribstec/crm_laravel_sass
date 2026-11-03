<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports currentQualityHistorySubquery()'s ranking of the three quality
     * "decision" sub-stages (quality_reject, quality_cleared,
     * quality_cleared_no_job) — WHERE sub_stage IN (...) then
     * ROW_NUMBER() OVER (PARTITION BY applicant_id, sale_id ORDER BY id DESC),
     * selecting created_at. None of the existing history indexes cover
     * created_at, so MySQL fell back to a full 550k-row table scan instead of
     * using them (verified via EXPLAIN). This index lets the whole query run
     * as an index-only scan.
     */
    public function up(): void
    {
        Schema::table('history', function (Blueprint $table) {
            if (!$this->indexExists('history', 'idx_history_quality_decision')) {
                $table->index(
                    ['sub_stage', 'applicant_id', 'sale_id', 'id', 'created_at'],
                    'idx_history_quality_decision'
                );
            }
        });
    }

    public function down(): void
    {
        // Blueprint has no dropIndexIfExists(): guard with hasIndex so a rollback works.
        foreach ([
            ['history', 'idx_history_quality_decision'],
        ] as [$table, $index]) {
            \App\Support\SchemaIndex::dropIfExists($table, $index);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }
};
