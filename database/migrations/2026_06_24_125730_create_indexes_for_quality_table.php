<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // applicants — WHERE status = 1 (filters the entire base query)
        // ---------------------------------------------------------------
        Schema::table('applicants', function (Blueprint $table) {
            if (!Schema::hasIndex('applicants', 'idx_applicants_status_id')) {   // guard (M-06)
                $table->index(['status', 'id'], 'idx_applicants_status_id');
            }
        });

        // ---------------------------------------------------------------
        // quality_notes — ROW_NUMBER() PARTITION BY applicant_id, sale_id
        // ORDER BY id DESC, filtered by moved_tab_to
        // ---------------------------------------------------------------
        Schema::table('quality_notes', function (Blueprint $table) {
            if (!Schema::hasIndex('quality_notes', 'idx_qn_tab_applicant_sale_id')) {   // guard (M-06)
                $table->index(
                    ['moved_tab_to', 'applicant_id', 'sale_id', 'id'],
                    'idx_qn_tab_applicant_sale_id'
                );
            }
        });

        // ---------------------------------------------------------------
        // cv_notes — ROW_NUMBER() PARTITION BY applicant_id, sale_id
        // ORDER BY id DESC/ASC, filtered by status
        // ---------------------------------------------------------------
        Schema::table('cv_notes', function (Blueprint $table) {
            if (!Schema::hasIndex('cv_notes', 'idx_cn_status_applicant_sale_id')) {   // guard (M-06)
                $table->index(
                    ['status', 'applicant_id', 'sale_id', 'id'],
                    'idx_cn_status_applicant_sale_id'
                );
            }
            // For earliestCvNoteSubquery (no status filter)
            if (!Schema::hasIndex('cv_notes', 'idx_cn_applicant_sale_id')) {   // guard (M-06)
                $table->index(
                    ['applicant_id', 'sale_id', 'id'],
                    'idx_cn_applicant_sale_id'
                );
            }
        });

        // ---------------------------------------------------------------
        // history — ROW_NUMBER() PARTITION BY applicant_id, sale_id
        // filtered by sub_stage + status
        // ---------------------------------------------------------------
        Schema::table('history', function (Blueprint $table) {
            if (!Schema::hasIndex('history', 'idx_history_substage_status_applicant_sale')) {   // guard (M-06)
                $table->index(
                    ['sub_stage', 'status', 'applicant_id', 'sale_id', 'id'],
                    'idx_history_substage_status_applicant_sale'
                );
            }
        });

        // ---------------------------------------------------------------
        // revert_stages — ROW_NUMBER() PARTITION BY applicant_id, sale_id
        // filtered by stage
        // ---------------------------------------------------------------
        Schema::table('revert_stages', function (Blueprint $table) {
            if (!Schema::hasIndex('revert_stages', 'idx_revert_stage_applicant_sale_id')) {   // guard (M-06)
                $table->index(
                    ['stage', 'applicant_id', 'sale_id', 'id'],
                    'idx_revert_stage_applicant_sale_id'
                );
            }
        });

        // ---------------------------------------------------------------
        // sales — joined on sales.id, office_id, unit_id
        // ---------------------------------------------------------------
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasIndex('sales', 'idx_sales_office_unit')) {   // guard (M-06)
                $table->index(['office_id', 'unit_id'], 'idx_sales_office_unit');
            }
        });
    }

    public function down(): void
    {
        foreach ([
            ['applicants', 'idx_applicants_status_id'],
            ['quality_notes', 'idx_qn_tab_applicant_sale_id'],
            ['cv_notes', 'idx_cn_status_applicant_sale_id'],
            ['cv_notes', 'idx_cn_applicant_sale_id'],
            ['history', 'idx_history_substage_status_applicant_sale'],
            ['revert_stages', 'idx_revert_stage_applicant_sale_id'],
            ['sales', 'idx_sales_office_unit'],
        ] as [$table, $index]) {
            \App\Support\SchemaIndex::dropIfExists($table, $index);
        }
    }
};