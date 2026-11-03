<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Index helpers for migrations (rollbacks that work on a real database). */
final class SchemaIndex
{
    /**
     * Drop an index if it exists. InnoDB silently drops a foreign key's own index once a composite
     * index starting with the same column can serve it, and then refuses to drop that composite
     * (error 1553); in that case the FK column gets a plain index first.
     */
    public static function dropIfExists(string $table, string $index): void
    {
        if (!Schema::hasTable($table) || !Schema::hasIndex($table, $index)) {
            return;
        }
        $indexes = collect(Schema::getIndexes($table));
        $first = $indexes->first(fn ($i) => strcasecmp($i['name'], $index) === 0)['columns'][0] ?? null;
        if ($first !== null) {
            $otherIndex = $indexes->contains(fn ($i) => strcasecmp($i['name'], $index) !== 0 && ($i['columns'][0] ?? null) === $first);
            $usedByForeignKey = collect(Schema::getForeignKeys($table))->contains(fn ($fk) => ($fk['columns'][0] ?? null) === $first);
            if ($usedByForeignKey && !$otherIndex) {
                Schema::table($table, fn (Blueprint $t) => $t->index([$first]));
            }
        }
        Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
    }
}
