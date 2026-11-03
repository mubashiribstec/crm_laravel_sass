<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up getModuleContacts() lookups:
 * WHERE contactable_type = ? AND contactable_id = ? ORDER BY id DESC
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!$this->indexExists('contacts', 'idx_contacts_type_id_id')) {
                $table->index(
                    ['contactable_type', 'contactable_id', 'id'],
                    'idx_contacts_type_id_id'
                );
            }
        });
    }

    public function down(): void
    {
        // A try/catch inside the Blueprint closure never caught anything (the drop runs after it):
        // guard with hasIndex so a rollback works when an index is already gone.
        foreach ([
            ['contacts', 'idx_contacts_type_id_id'],
        ] as [$table, $index]) {
            \App\Support\SchemaIndex::dropIfExists($table, $index);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
    }
};
