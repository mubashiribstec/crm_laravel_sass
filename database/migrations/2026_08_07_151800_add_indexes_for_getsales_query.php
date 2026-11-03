<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes that make getSales() cheap on live.
 *
 * The page joins three derived aggregations on every DataTables request
 * (including the recordsFiltered COUNT):
 *   - sale_notes:  WHERE status = 1 GROUP BY sale_id  → MAX(id)
 *   - cv_notes:    WHERE status = 1 GROUP BY sale_id  → COUNT(*)
 *   - audits:      WHERE auditable_type + message IN (...) GROUP BY auditable_id → MAX(id)
 *
 * Without covering indexes MySQL has to scan/sort the whole notes/audits
 * tables for each request. Local feels fine with a warm buffer pool;
 * live (larger data + colder cache) pays the full cost every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_notes', function (Blueprint $table) {
            // Covers: WHERE status = 1 GROUP BY sale_id → MAX(id)
            if (!$this->indexExists('sale_notes', 'idx_sale_notes_status_sale_id')) {
                $table->index(['status', 'sale_id', 'id'], 'idx_sale_notes_status_sale_id');
            }
        });

        Schema::table('cv_notes', function (Blueprint $table) {
            // Covers: WHERE status = 1 GROUP BY sale_id → COUNT(*)
            // Existing idx_cv_notes_sale_status is (sale_id, status) — wrong leading
            // column for a status-first filter over the whole table.
            if (!$this->indexExists('cv_notes', 'idx_cv_notes_status_sale_id')) {
                $table->index(['status', 'sale_id'], 'idx_cv_notes_status_sale_id');
            }
        });

        Schema::table('audits', function (Blueprint $table) {
            // Covers: WHERE auditable_type = ? AND message IN (...) GROUP BY auditable_id → MAX(id)
            // Existing idx_auditable is (type, id) and idx_audit_msg is (type, message) —
            // neither lets MySQL stream the GROUP BY off an index.
            if (!$this->indexExists('audits', 'idx_audits_type_msg_id')) {
                $table->index(
                    ['auditable_type', 'message', 'auditable_id', 'id'],
                    'idx_audits_type_msg_id'
                );
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            // Open/closed/pending tabs filter on these together and sort by updated_at.
            if (!$this->indexExists('sales', 'idx_sales_status_hold_updated')) {
                $table->index(['status', 'is_on_hold', 'updated_at'], 'idx_sales_status_hold_updated');
            }
            if (!$this->indexExists('sales', 'idx_sales_job_source_id')) {
                $table->index('job_source_id', 'idx_sales_job_source_id');
            }
        });
    }

    public function down(): void
    {
        // A try/catch inside the Blueprint closure never caught anything (the drop runs after it):
        // guard with hasIndex so a rollback works when an index is already gone.
        foreach ([
            ['sale_notes', 'idx_sale_notes_status_sale_id'],
            ['cv_notes', 'idx_cv_notes_status_sale_id'],
            ['audits', 'idx_audits_type_msg_id'],
            ['sales', 'idx_sales_status_hold_updated'],
            ['sales', 'idx_sales_job_source_id'],
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
