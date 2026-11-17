<?php

namespace App\Console\Commands;

use App\Support\SynopsisFormStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Moves Send CV synopsis forms that are still public files (public/uploads/synopsisForms/… or
 * public/uploads/drafts/…) into private storage (storage/app/private/synopsis-forms/…, random
 * names) and points their rows at them.
 *
 * Safe to run more than once: each file is copied, checked, the row updated (keeping its
 * updated_at), and only then the public copy removed. A row whose file is missing is listed and
 * left as it is.
 */
class MoveSynopsisFormsToPrivate extends Command
{
    protected $signature = 'synopsis:move-to-private {--dry-run : List what would move, change nothing}';

    protected $description = 'Move Send CV synopsis forms out of public/uploads into private storage';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $moved = 0;
        $missing = [];
        $failed = 0;

        DB::table('applicants_synopsis_forms')
            ->where(function ($q) {
                foreach (SynopsisFormStorage::LEGACY_FOLDERS as $folder) {
                    $q->orWhere('file_url', 'like', $folder . '%');
                }
            })
            ->select(['id', 'file_url'])->orderBy('id')
            ->chunkById(200, function ($rows) use ($dry, &$moved, &$missing, &$failed) {
                foreach ($rows as $row) {
                    $source = SynopsisFormStorage::existingPath($row->file_url);
                    if ($source === null) {
                        $missing[] = "#{$row->id}: {$row->file_url}";
                        continue;
                    }
                    if ($dry) {
                        $moved++;
                        continue;
                    }
                    $new = SynopsisFormStorage::newPrivatePath(pathinfo($source, PATHINFO_EXTENSION) ?: 'docx',
                        preg_match('#^uploads/(?:synopsisForms|drafts)/(\d{4})/(\d{2})/#', $row->file_url, $m)
                            ? "{$m[1]}/{$m[2]}" : null);
                    $target = SynopsisFormStorage::fullPath($new);
                    File::ensureDirectoryExists(dirname($target), 0750);
                    if (!@copy($source, $target) || filesize($target) !== filesize($source)) {
                        @unlink($target);
                        $failed++;
                        $this->error("Could not copy {$row->file_url} (#{$row->id}); left where it is.");
                        continue;
                    }
                    DB::table('applicants_synopsis_forms')->where('id', $row->id)
                        ->update(['file_url' => $new, 'updated_at' => DB::raw('updated_at')]);
                    SynopsisFormStorage::delete($row->file_url);
                    $moved++;
                }
            });

        $this->info(($dry ? 'Would move ' : 'Moved ') . "{$moved} synopsis form(s)" . ($failed ? ", {$failed} failed" : '') . '.');
        if ($missing !== []) {
            $this->warn(count($missing) . ' row(s) point at a file that does not exist (left unchanged):');
            foreach (array_slice($missing, 0, 20) as $line) {
                $this->line('  ' . $line);
            }
        }
        if (!$dry) {
            $this->line('The web server must not serve /uploads/synopsisForms/ or /uploads/drafts/ (docker/nginx.conf and public/.htaccess block them).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
