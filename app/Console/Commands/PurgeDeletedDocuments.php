<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PurgeDeletedDocuments extends Command
{
    protected $signature = 'attachments:purge-deleted
                            {--dry-run : Show expired attachments without deleting files or records}';

    protected $description = 'Permanently remove office and unit attachments soft-deleted for at least three days';

    public function handle(): int
    {
        $cutoff = now()->subDays(3);
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $failures = 0;

        foreach (['offices_documents', 'units_documents'] as $table) {
            DB::table($table)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<=', $cutoff)
                ->orderBy('id')
                ->chunkById(100, function ($documents) use ($table, $cutoff, $dryRun, &$deleted, &$failures) {
                    foreach ($documents as $document) {
                        if ($dryRun) {
                            $deleted++;
                            continue;
                        }

                        try {
                            if (!$this->deleteDocumentFile($document->document_path)) {
                                $failures++;
                                continue;
                            }

                            $affected = DB::table($table)
                                ->where('id', $document->id)
                                ->whereNotNull('deleted_at')
                                ->where('deleted_at', '<=', $cutoff)
                                ->delete();

                            if ($affected === 1) {
                                $deleted++;
                            } else {
                                $failures++;
                                Log::warning('Expired attachment was not purged because its database record changed.', [
                                    'table' => $table,
                                    'document_id' => $document->id,
                                ]);
                            }
                        } catch (Throwable $exception) {
                            $failures++;
                            Log::error('Unable to permanently purge an expired attachment.', [
                                'table' => $table,
                                'document_id' => $document->id,
                                'exception' => $exception->getMessage(),
                            ]);
                        }
                    }
                });
        }

        $action = $dryRun ? 'Would permanently purge' : 'Permanently purged';
        $this->info("{$action} {$deleted} attachment(s).");

        if ($failures > 0) {
            $this->error("Failed to purge {$failures} attachment(s). See the application log for details.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteDocumentFile(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $segments = explode('/', $relativePath);

        if (!Str::startsWith($relativePath, 'uploads/docs/') || in_array('..', $segments, true)) {
            Log::warning('Skipped purging an attachment with an unsafe file path.', [
                'document_path' => $relativePath,
            ]);

            return false;
        }

        $path = public_path($relativePath);
        if (!File::exists($path)) {
            return true;
        }

        $docsRoot = realpath(public_path('uploads/docs'));
        $realPath = realpath($path);
        if ($docsRoot === false || $realPath === false || !File::isFile($realPath)) {
            Log::warning('Skipped purging an attachment because its file path could not be verified.', [
                'document_path' => $relativePath,
            ]);

            return false;
        }

        $rootPrefix = rtrim($docsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!Str::startsWith(strtolower($realPath), strtolower($rootPrefix))) {
            Log::warning('Skipped purging an attachment outside the documents directory.', [
                'document_path' => $relativePath,
            ]);

            return false;
        }

        if (!File::delete($realPath) || File::exists($realPath)) {
            Log::error('Unable to delete an expired attachment file.', [
                'document_path' => $relativePath,
            ]);

            return false;
        }

        return true;
    }
}
