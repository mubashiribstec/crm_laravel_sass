<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Search support for ApplicantController::getApplicantsAjaxRequest().
 *
 *  - applicant_postcode_normalized: stored generated column holding the postcode
 *    upper-cased with spaces and dashes removed ("kt2 7eg" -> "KT27EG"), so a
 *    postcode search can use `LIKE 'KT27%'` on an index whether the user typed
 *    the space or not, instead of REPLACE()-ing every row.
 *  - applicant_name / applicant_email / applicant_email_secondary: the only
 *    existing indexes on these lead with user_id, so prefix searches scanned
 *    the whole table.
 *  - FULLTEXT on applicant_name: word-prefix search ("smi" finds "John Smith")
 *    without a leading-wildcard LIKE.
 *
 * Adding the stored column rebuilds the table (copy), so run it off-peak.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('applicants', 'applicant_postcode_normalized')) {
            Schema::table('applicants', function (Blueprint $table) {
                $table->string('applicant_postcode_normalized', 50)
                    ->nullable()
                    ->storedAs("REPLACE(REPLACE(UPPER(`applicant_postcode`), ' ', ''), '-', '')")
                    ->after('applicant_postcode');
            });
        }

        // 2026_04_01_000000_add_search_indexes_to_applicants may already have indexed some of
        // these under other names (and its idx_applicant_email_secondary is actually on
        // applicant_email), so check by leading column rather than by index name.
        $indexes = [
            'applicant_postcode_normalized' => 'idx_applicants_postcode_normalized',
            'applicant_name' => 'idx_applicants_name',
            'applicant_email' => 'idx_applicants_email',
            'applicant_email_secondary' => 'idx_applicants_email_secondary',
        ];

        Schema::table('applicants', function (Blueprint $table) use ($indexes) {
            foreach ($indexes as $column => $indexName) {
                if (!$this->hasLeadingBtreeIndex('applicants', $column)) {
                    $table->index($column, $indexName);
                }
            }
        });

        if (!$this->indexExists('applicants', 'ft_applicants_name')) {
            Schema::table('applicants', function (Blueprint $table) {
                $table->fullText('applicant_name', 'ft_applicants_name');
            });
        }
    }

    public function down(): void
    {
        foreach (['ft_applicants_name', 'idx_applicants_email_secondary', 'idx_applicants_email', 'idx_applicants_name', 'idx_applicants_postcode_normalized'] as $indexName) {
            if ($this->indexExists('applicants', $indexName)) {
                Schema::table('applicants', fn (Blueprint $t) => $t->dropIndex($indexName));
            }
        }

        if (Schema::hasColumn('applicants', 'applicant_postcode_normalized')) {
            Schema::table('applicants', fn (Blueprint $t) => $t->dropColumn('applicant_postcode_normalized'));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    private function hasLeadingBtreeIndex(string $table, string $column): bool
    {
        $indexes = DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Seq_in_index = 1 AND Index_type = 'BTREE'",
            [$column]
        );
        return count($indexes) > 0;
    }
};
