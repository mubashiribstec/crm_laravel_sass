<?php

namespace App\Console\Commands;

use App\Support\CvStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Moves the CVs that are still public files (public/uploads/…) into private storage
 * (storage/app/private/resumes/…, random names) and points the applicant columns at them.
 *
 * Safe to run more than once, and while the CRM is in use: each file is copied, checked,
 * the row updated, and only then the public copy removed. Rows keep their updated_at (the
 * Resources tabs and dashboard counts are based on it). A path whose file is
 * missing is left as it is and listed.
 */
class MoveCvsToPrivate extends Command
{
    protected $signature = 'cv:move-to-private {--dry-run : List what would move, change nothing}';

    protected $description = 'Move applicant CVs out of public/uploads into private storage';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $moved = [];                                  // old path => new path (shared files move once)
        $missing = [];
        $rows = 0;
        $failed = 0;

        DB::table('applicants')
            ->where(fn ($q) => $q->where('applicant_cv', 'like', CvStorage::LEGACY_PREFIX . '%')
                ->orWhere('updated_cv', 'like', CvStorage::LEGACY_PREFIX . '%'))
            ->select(['id', 'applicant_cv', 'updated_cv'])
            ->orderBy('id')
            ->chunkById(500, function ($applicants) use ($dry, &$moved, &$missing, &$rows, &$failed) {
                foreach ($applicants as $applicant) {
                    $changes = [];
                    foreach (CvStorage::KINDS as $column) {
                        $old = (string) $applicant->{$column};
                        if (!str_starts_with($old, CvStorage::LEGACY_PREFIX)) {
                            continue;
                        }
                        if (isset($moved[$old])) {
                            $changes[$column] = $moved[$old];
                            continue;
                        }
                        $source = CvStorage::existingPath($old);
                        if ($source === null) {
                            $missing[] = $applicant->id . ' ' . $column . ': ' . $old;
                            continue;
                        }
                        if ($dry) {
                            $moved[$old] = '(dry run)';
                            $changes[$column] = '(dry run)';
                            continue;
                        }
                        $new = CvStorage::newPrivatePath(pathinfo($source, PATHINFO_EXTENSION), $this->yearMonth($old));
                        $target = storage_path('app/' . $new);
                        File::ensureDirectoryExists(dirname($target), 0750);
                        if (!@copy($source, $target) || filesize($target) !== filesize($source)) {
                            @unlink($target);
                            $failed++;
                            $this->error("Could not copy {$old} (applicant {$applicant->id}); left where it is.");
                            continue;
                        }
                        $moved[$old] = $new;
                        $changes[$column] = $new;
                    }
                    if ($changes === []) {
                        continue;
                    }
                    $rows++;
                    if (!$dry) {
                        // updated_at = itself: the column auto-updates on any write otherwise.
                        DB::table('applicants')->where('id', $applicant->id)
                            ->update($changes + ['updated_at' => DB::raw('updated_at')]);
                    }
                }
            });

        if (!$dry) {
            // Every row that pointed at a file now points at its private copy: remove the public one.
            foreach (array_keys($moved) as $old) {
                CvStorage::delete($old);
            }
        }

        $files = count($moved);
        $this->info(($dry ? 'Would move ' : 'Moved ') . "{$files} file(s) for {$rows} applicant(s)"
            . ($failed ? ", {$failed} failed" : '') . '.');
        if ($missing !== []) {
            $this->warn(count($missing) . ' CV path(s) point at a file that does not exist (left unchanged):');
            foreach (array_slice($missing, 0, 20) as $line) {
                $this->line('  applicant ' . $line);
            }
            if (count($missing) > 20) {
                $this->line('  … and ' . (count($missing) - 20) . ' more.');
            }
        }
        $left = is_dir(public_path('uploads/resume')) ? count(File::allFiles(public_path('uploads/resume'))) : 0;
        if (!$dry && $left > 0) {
            $this->line("{$left} file(s) remain in public/uploads/resume that no applicant points at (old replaced CVs). "
                . 'They are not linked anywhere; delete them once you have a backup.');
        }
        if (!$dry) {
            $this->line('Now block the old folder in the web server (see docs/audit/2026-11-03-audit-review.md, N-04).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** "uploads/resume/2025/03/14/x.pdf" keeps its year/month; anything else goes under this month. */
    private function yearMonth(string $old): ?string
    {
        return preg_match('#^uploads/resume/(\d{4})/(\d{2})/#', $old, $m) ? $m[1] . '/' . $m[2] : null;
    }
}
