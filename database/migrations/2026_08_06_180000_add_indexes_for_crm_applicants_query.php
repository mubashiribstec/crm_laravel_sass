<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // crm_notes — MAX(id) GROUP BY applicant_id, sale_id
        // filtered by status + moved_tab_to (getCrmApplicantsAjaxRequest)
        // ---------------------------------------------------------------
        Schema::table('crm_notes', function (Blueprint $table) {
            if (!$this->indexExists('crm_notes', 'idx_crm_notes_status_tab_applicant_sale')) {
                $table->index(
                    ['status', 'moved_tab_to', 'applicant_id', 'sale_id', 'id'],
                    'idx_crm_notes_status_tab_applicant_sale'
                );
            }
        });

        // ---------------------------------------------------------------
        // sent_emails — latest email per applicant+sale (CRM "request" tabs)
        // ---------------------------------------------------------------
        Schema::table('sent_emails', function (Blueprint $table) {
            if (!$this->indexExists('sent_emails', 'idx_sent_emails_applicant_sale_created')) {
                $table->index(
                    ['applicant_id', 'sale_id', 'created_at'],
                    'idx_sent_emails_applicant_sale_created'
                );
            }
        });

        // ---------------------------------------------------------------
        // messages — latest incoming message per phone number (CRM action column)
        // ---------------------------------------------------------------
        Schema::table('messages', function (Blueprint $table) {
            if (!$this->indexExists('messages', 'idx_messages_phone_status_type_created')) {
                $table->index(
                    ['phone_number', 'status', 'module_type', 'created_at'],
                    'idx_messages_phone_status_type_created'
                );
            }
        });
    }

    public function down(): void
    {
        // Blueprint has no dropIndexIfExists(): guard with hasIndex so a rollback works.
        foreach ([
            ['crm_notes', 'idx_crm_notes_status_tab_applicant_sale'],
            ['sent_emails', 'idx_sent_emails_applicant_sale_created'],
            ['messages', 'idx_messages_phone_status_type_created'],
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
