<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up "latest module note per sale" lookups used by getSales()
 * enrichment (same source as the Notes History CTA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_notes', function (Blueprint $table) {
            if (!$this->indexExists('module_notes', 'idx_module_notes_type_idable_id')) {
                $table->index(
                    ['module_noteable_type', 'module_noteable_id', 'id'],
                    'idx_module_notes_type_idable_id'
                );
            }
        });
    }

    public function down(): void
    {
        // A try/catch inside the Blueprint closure never caught anything (the drop runs after it):
        // guard with hasIndex so a rollback works when an index is already gone.
        foreach ([
            ['module_notes', 'idx_module_notes_type_idable_id'],
        ] as [$table, $index]) {
            \App\Support\SchemaIndex::dropIfExists($table, $index);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
    }
};
