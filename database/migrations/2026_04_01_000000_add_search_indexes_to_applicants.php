<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            // Add indexes for fast search/filtering if they don't exist
            // These allow prefix searches to be very fast

            if (!$this->indexExists('applicants', 'idx_applicant_name')) {
                $table->index('applicant_name', 'idx_applicant_name');
            }

            if (!$this->indexExists('applicants', 'idx_applicant_email')) {
                $table->index('applicant_email', 'idx_applicant_email');
            }

            if (!$this->indexExists('applicants', 'idx_applicant_email_secondary')) {
                $table->index('applicant_email', 'idx_applicant_email_secondary');
            }

            if (!$this->indexExists('applicants', 'idx_applicant_postcode')) {
                $table->index('applicant_postcode', 'idx_applicant_postcode');
            }

            if (!$this->indexExists('applicants', 'idx_applicant_phone')) {
                $table->index('applicant_phone', 'idx_applicant_phone');
            }

            if (!$this->indexExists('applicants', 'idx_applicant_phone_secondary')) {
                $table->index('applicant_phone_secondary', 'idx_applicant_phone_secondary');
            }

            if (!$this->indexExists('applicants', 'idx_applicant_landline')) {
                $table->index('applicant_landline', 'idx_applicant_landline');
            }

            // Composite index for common filter + search patterns
            if (!$this->indexExists('applicants', 'idx_status_created')) {
                $table->index(['status', 'created_at'], 'idx_status_created');
            }

            if (!$this->indexExists('applicants', 'idx_blocked_status')) {
                $table->index(['is_blocked', 'status'], 'idx_blocked_status');
            }
        });
    }

    public function down(): void
    {
        // Blueprint has no dropIndexIfExists(), so this used to fail on every rollback; it also forgot
        // idx_applicant_email_secondary (M-07). idx_applicant_phone_secondary belongs to 2025_12_30
        // (this migration only adds it when missing), so it is left to that migration's rollback.
        foreach (['idx_applicant_name', 'idx_applicant_email', 'idx_applicant_email_secondary', 'idx_applicant_postcode',
                     'idx_applicant_phone', 'idx_applicant_landline', 'idx_status_created', 'idx_blocked_status'] as $index) {
            \App\Support\SchemaIndex::dropIfExists('applicants', $index);
        }
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists($table, $indexName): bool
    {
        $indexes = Schema::getConnection()
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($indexes);
    }
};
