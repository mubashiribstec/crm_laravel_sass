<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for CrmController::getCrmApplicantsAjaxRequest().
 *
 * Most CRM tabs pick the latest/earliest note per applicant+sale with
 * `MAX(id) / MIN(id) ... GROUP BY applicant_id, sale_id`. The existing indexes
 * cover the variants that filter on `status` first, but not these:
 *
 *  - crm_notes filtered on moved_tab_to only (no status): paid, declined,
 *    dispute, rebook, invoice, invoice sent, start date, start date hold,
 *    not attended, attended to pre-start date, rejected cvs, rejected by request,
 *    request (no response). idx_crm_notes_status_tab_applicant_sale leads with
 *    `status`, so it can't be used and the GROUP BY falls back to a temp table.
 *  - crm_notes filtered on status only (sent cvs (no job)).
 *  - quality_notes with no filter at all (sent cvs (no job)).
 *  - interviews, left-joined on applicant_id + sale_id + status in every tab;
 *    the only composite index leads with user_id.
 *
 * Indexes only: no data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_notes', function (Blueprint $table) {
            if (!$this->indexExists('crm_notes', 'idx_crm_notes_tab_applicant_sale_id')) {
                $table->index(
                    ['moved_tab_to', 'applicant_id', 'sale_id', 'id'],
                    'idx_crm_notes_tab_applicant_sale_id'
                );
            }

            if (!$this->indexExists('crm_notes', 'idx_crm_notes_status_applicant_sale_id')) {
                $table->index(
                    ['status', 'applicant_id', 'sale_id', 'id'],
                    'idx_crm_notes_status_applicant_sale_id'
                );
            }
        });

        Schema::table('quality_notes', function (Blueprint $table) {
            if (!$this->indexExists('quality_notes', 'idx_qn_applicant_sale_id')) {
                $table->index(
                    ['applicant_id', 'sale_id', 'id'],
                    'idx_qn_applicant_sale_id'
                );
            }
        });

        Schema::table('interviews', function (Blueprint $table) {
            if (!$this->indexExists('interviews', 'idx_interviews_applicant_sale_status')) {
                $table->index(
                    ['applicant_id', 'sale_id', 'status'],
                    'idx_interviews_applicant_sale_status'
                );
            }
        });
    }

    public function down(): void
    {
        $indexes = [
            'crm_notes' => ['idx_crm_notes_tab_applicant_sale_id', 'idx_crm_notes_status_applicant_sale_id'],
            'quality_notes' => ['idx_qn_applicant_sale_id'],
            'interviews' => ['idx_interviews_applicant_sale_status'],
        ];

        foreach ($indexes as $tableName => $indexNames) {
            foreach ($indexNames as $indexName) {
                \App\Support\SchemaIndex::dropIfExists($tableName, $indexName);   // handles FK-backing indexes
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
