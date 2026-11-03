<?php

namespace App\Console\Commands;

use App\Support\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Moves office and unit attachments that are still public files (public/uploads/docs/…) into
 * private storage (storage/app/private/documents/…, random names) and points their rows at
 * them. Soft-deleted attachments move too (they can still be restored).
 *
 * Safe to run more than once: each file is copied, checked, the row updated (keeping its
 * updated_at), and only then the public copy removed. A row whose file is missing is listed and
 * left as it is.
 */
class MoveDocumentsToPrivate extends Command
{
    protected $signature = 'documents:move-to-private {--dry-run : List what would move, change nothing}';

    protected $description = 'Move office and unit attachments out of public/uploads/docs into private storage';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $moved = 0;
        $missing = [];
        $failed = 0;

        foreach (['offices_documents', 'units_documents'] as $table) {
            DB::table($table)->where('document_path', 'like', DocumentStorage::LEGACY_PREFIX . '%')
                ->select(['id', 'document_path'])->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $dry, &$moved, &$missing, &$failed) {
                    foreach ($rows as $row) {
                        $source = DocumentStorage::existingPath($row->document_path);
                        if ($source === null) {
                            $missing[] = "{$table} #{$row->id}: {$row->document_path}";
                            continue;
                        }
                        if ($dry) {
                            $moved++;
                            continue;
                        }
                        $new = DocumentStorage::newPrivatePath(pathinfo($source, PATHINFO_EXTENSION),
                            preg_match('#^uploads/docs/(\d{4})/(\d{2})/#', $row->document_path, $m) ? "{$m[1]}/{$m[2]}" : null);
                        $target = storage_path('app/' . $new);
                        File::ensureDirectoryExists(dirname($target), 0750);
                        if (!@copy($source, $target) || filesize($target) !== filesize($source)) {
                            @unlink($target);
                            $failed++;
                            $this->error("Could not copy {$row->document_path} ({$table} #{$row->id}); left where it is.");
                            continue;
                        }
                        // updated_at = itself: it would otherwise auto-update on some installs.
                        DB::table($table)->where('id', $row->id)
                            ->update(['document_path' => $new, 'updated_at' => DB::raw('updated_at')]);
                        DocumentStorage::delete($row->document_path);
                        $moved++;
                    }
                });
        }

        $this->info(($dry ? 'Would move ' : 'Moved ') . "{$moved} attachment(s)" . ($failed ? ", {$failed} failed" : '') . '.');
        if ($missing !== []) {
            $this->warn(count($missing) . ' attachment(s) point at a file that does not exist (left unchanged):');
            foreach (array_slice($missing, 0, 20) as $line) {
                $this->line('  ' . $line);
            }
        }
        if (!$dry) {
            $this->line('Now block the old folder in the web server: /uploads/docs/ (docker/nginx.conf and public/.htaccess do).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
