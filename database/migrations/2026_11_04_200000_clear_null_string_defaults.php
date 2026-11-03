<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Six columns were created with ->default('Null') (the 4-letter word, not SQL NULL), so rows
 * without a value hold "Null" and whereNull()/is_null() miss them (audit M-02). Turn those
 * strings into real NULLs and make the default NULL.
 */
return new class extends Migration
{
    private const COLUMNS = [
        ['ip_addresses', 'mac_address', 100],
        ['ip_addresses', 'device_type', 50],
        ['cv_notes', 'cv_uid', 255],
        ['email_templates', 'from_email', 255],
        ['email_templates', 'subject', 255],
        ['history', 'history_uid', 255],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column, $length]) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }
            DB::table($table)->whereIn($column, ['Null', 'NULL'])->update([$column => null]);
            Schema::table($table, fn (Blueprint $t) => $t->string($column, $length)->nullable()->default(null)->change());
        }
    }

    public function down(): void
    {
        // Not restored: the 'Null' string default was the bug.
    }
};
