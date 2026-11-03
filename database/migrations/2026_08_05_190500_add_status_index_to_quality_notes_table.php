<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * getResourcesByTypeAjaxRequest() (QualityController) resolves the latest
     * ACTIVE quality note per (applicant_id, sale_id) via:
     *   WHERE status = 1
     *   ROW_NUMBER() OVER (PARTITION BY applicant_id, sale_id ORDER BY id DESC)
     * The existing idx_qn_tab_applicant_sale_id index leads with
     * `moved_tab_to`, so it cannot be used for this status-first lookup —
     * this leaves the query doing a full table scan + filesort.
     */
    public function up(): void
    {
        Schema::table('quality_notes', function (Blueprint $table) {
            if (!$this->indexExists('quality_notes', 'idx_qn_status_applicant_sale_id')) {
                $table->index(
                    ['status', 'applicant_id', 'sale_id', 'id'],
                    'idx_qn_status_applicant_sale_id'
                );
            }
        });

        // The FORCE INDEX(idx_revert_grp_v2) hint previously used in
        // QualityController referenced an index that was never created by any
        // migration (the real one is idx_revert_stage_applicant_sale_id below).
        // Ensure the correctly named index exists so the optimizer can pick it
        // naturally now that the hint has been removed from the query.
        Schema::table('revert_stages', function (Blueprint $table) {
            if (!$this->indexExists('revert_stages', 'idx_revert_stage_applicant_sale_id')) {
                $table->index(
                    ['stage', 'applicant_id', 'sale_id', 'id'],
                    'idx_revert_stage_applicant_sale_id'
                );
            }
        });
    }

    public function down(): void
    {
        // A try/catch inside the Blueprint closure never caught anything (the drop runs after it):
        // guard with hasIndex so a rollback works when an index is already gone.
        foreach ([
            ['quality_notes', 'idx_qn_status_applicant_sale_id'],
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
