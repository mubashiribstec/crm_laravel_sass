<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `messages` previously only had single-column indexes on module_id, module_type,
     * user_id and msg_id. The Communication/Messages inbox queries filter/group on
     * combinations of these columns (plus status, is_read, phone_number, id), which
     * forced MySQL to fall back to full/large scans + filesorts. These composite
     * indexes cover the actual access patterns used by CommunicationController:
     *  - getChatBoxMessages(): WHERE module_type = ? AND module_id = ? ORDER BY id DESC
     *    (+ WHERE id < ? for "load older messages")
     *  - getApplicantsForMessage()/getChatBoxMessages(): WHERE module_type = ? AND
     *    module_id = ? AND status = ? AND is_read = ? (unread counts / mark-as-read)
     *  - getUnknownMessage()/getChatBoxMessages(): WHERE phone_number = ? ORDER BY id
     *  - getUserChats(): WHERE user_id = ? AND module_type = ? GROUP BY module_id
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!$this->indexExists('messages', 'idx_messages_module_type_id_id')) {
                $table->index(['module_type', 'module_id', 'id'], 'idx_messages_module_type_id_id');
            }

            if (!$this->indexExists('messages', 'idx_messages_module_status_read')) {
                // Trailing `id` lets the getApplicantsForMessage() stats aggregation
                // (MAX(id), COUNT(*), SUM(CASE status/is_read...) GROUP BY module_id)
                // be satisfied entirely from the index without touching the base rows.
                $table->index(['module_type', 'module_id', 'status', 'is_read', 'id'], 'idx_messages_module_status_read');
            }

            if (!$this->indexExists('messages', 'idx_messages_phone_id')) {
                $table->index(['phone_number', 'id'], 'idx_messages_phone_id');
            }

            if (!$this->indexExists('messages', 'idx_messages_user_module_type')) {
                $table->index(['user_id', 'module_type', 'module_id'], 'idx_messages_user_module_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Blueprint has no dropIndexIfExists(): guard with hasIndex so a rollback works.
        foreach ([
            ['messages', 'idx_messages_module_type_id_id'],
            ['messages', 'idx_messages_module_status_read'],
            ['messages', 'idx_messages_phone_id'],
            ['messages', 'idx_messages_user_module_type'],
        ] as [$table, $index]) {
            \App\Support\SchemaIndex::dropIfExists($table, $index);
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
