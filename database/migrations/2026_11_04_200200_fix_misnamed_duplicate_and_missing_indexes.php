<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index fixes from the audit, each decided by the columns an index really covers (not its name):
 * - M-04: idx_applicant_email_secondary indexed applicant_email (again); applicant_email_secondary
 *   had no index at all.
 * - M-05: idx_sales_status_hold_updated duplicates idx_sales_status_hold (same three columns).
 * - M-09: columns that are looked up but never had an index. Foreign keys are deliberately NOT added
 *   on log/history tables (call events, cleared CV reverts): those rows must outlive what they point
 *   at, and adding FKs could fail on existing orphans.
 */
return new class extends Migration
{
    public function up(): void
    {
        // M-04
        if (Schema::hasTable('applicants') && Schema::hasColumn('applicants', 'applicant_email_secondary')) {
            if ($this->indexColumns('applicants', 'idx_applicant_email_secondary') === ['applicant_email']) {
                Schema::table('applicants', fn (Blueprint $t) => $t->dropIndex('idx_applicant_email_secondary'));
            }
            $this->ensureIndex('applicants', ['applicant_email_secondary'], 'idx_applicant_email_secondary');
        }

        // M-05
        if ($this->indexColumns('sales', 'idx_sales_status_hold_updated') !== null
            && $this->indexColumns('sales', 'idx_sales_status_hold_updated') === $this->indexColumns('sales', 'idx_sales_status_hold')) {
            Schema::table('sales', fn (Blueprint $t) => $t->dropIndex('idx_sales_status_hold_updated'));
        }

        // M-09
        $this->ensureIndex('offices', ['job_source_id'], 'idx_offices_job_source_id');
        $this->ensureIndex('units', ['job_source_id'], 'idx_units_job_source_id');
        $this->ensureIndex('cleared_revert_cvs', ['cv_note_id'], 'idx_cleared_revert_cvs_cv_note_id');
        $this->ensureIndex('cleared_revert_cvs', ['user_id'], 'idx_cleared_revert_cvs_user_id');
        $this->ensureIndex('cleared_revert_cvs', ['reverted_by'], 'idx_cleared_revert_cvs_reverted_by');
        $this->ensureIndex('xplogent_call_events', ['call_log_id'], 'idx_xplogent_call_events_call_log_id');
    }

    public function down(): void
    {
        foreach ([['offices', 'idx_offices_job_source_id'], ['units', 'idx_units_job_source_id'],
                     ['cleared_revert_cvs', 'idx_cleared_revert_cvs_cv_note_id'],
                     ['cleared_revert_cvs', 'idx_cleared_revert_cvs_user_id'],
                     ['cleared_revert_cvs', 'idx_cleared_revert_cvs_reverted_by'],
                     ['xplogent_call_events', 'idx_xplogent_call_events_call_log_id']] as [$table, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    /** The columns an index covers, or null when there is no index of that name. */
    private function indexColumns(string $table, string $name): ?array
    {
        if (!Schema::hasTable($table)) {
            return null;
        }
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp($index['name'], $name) === 0) {
                return $index['columns'];
            }
        }

        return null;
    }

    /** Add an index on exactly these columns unless one already starts with them. */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table) || array_filter($columns, fn ($c) => !Schema::hasColumn($table, $c))) {
            return;
        }
        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return;                                   // an existing index already serves this lookup
            }
        }
        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }
};
