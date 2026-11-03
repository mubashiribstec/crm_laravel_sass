<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Where applicant CVs live, and the one way to link to them.
 *
 * New uploads go to storage/app/private/resumes/YYYY/MM/<random>.<ext>, outside the web
 * root, and are only reachable through the signed-in, permission-checked download route
 * (applicants.cv.download). Older CVs still sit in public/uploads/resume/…; the route
 * serves those too until `php artisan cv:move-to-private` moves them.
 *
 * Columns store the path relative to its root: "private/resumes/…" (storage/app) or
 * "uploads/…" (public). Nothing else is ever served, and ".." is refused.
 */
class CvStorage
{
    public const PRIVATE_PREFIX = 'private/resumes/';
    public const LEGACY_PREFIX = 'uploads/';

    /** Download kinds and the applicant column each one reads. */
    public const KINDS = ['original' => 'applicant_cv', 'updated' => 'updated_cv'];

    /** Save an upload privately under a random name; returns the value for the column. */
    public static function store(UploadedFile $file): string
    {
        $directory = self::PRIVATE_PREFIX . now()->format('Y/m');
        File::ensureDirectoryExists(storage_path('app/' . $directory), 0750);

        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension()));
        $name = Str::lower(Str::random(40)) . ($extension !== '' ? '.' . $extension : '');
        $file->move(storage_path('app/' . $directory), $name);

        return $directory . '/' . $name;
    }

    /** A new private path for an existing file (used when moving the old public CVs). */
    public static function newPrivatePath(string $extension, ?string $yearMonth = null): string
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $extension));

        return self::PRIVATE_PREFIX . ($yearMonth ?? now()->format('Y/m')) . '/' . Str::lower(Str::random(40))
            . ($extension !== '' ? '.' . $extension : '');
    }

    /** The absolute path a stored value points at (whether or not the file exists), or null. */
    public static function fullPath(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '' || str_contains($stored, '..') || str_contains($stored, "\0") || str_starts_with($stored, '/')) {
            return null;
        }
        if (str_starts_with($stored, self::PRIVATE_PREFIX)) {
            return storage_path('app/' . $stored);
        }
        if (str_starts_with($stored, self::LEGACY_PREFIX)) {
            return public_path($stored);
        }

        return null;
    }

    /** The absolute path of an existing CV file, or null (empty, missing, or not a CV location). */
    public static function existingPath(?string $stored): ?string
    {
        $full = self::fullPath($stored);
        if ($full === null || !is_file($full)) {
            return null;
        }
        $root = realpath(str_starts_with(trim((string) $stored), self::PRIVATE_PREFIX)
            ? storage_path('app/' . rtrim(self::PRIVATE_PREFIX, '/'))
            : public_path(rtrim(self::LEGACY_PREFIX, '/')));
        $real = realpath($full);

        // A symlink pointing outside the CV folders is not followed.
        return $root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
    }

    public static function exists(?string $stored): bool
    {
        return self::existingPath($stored) !== null;
    }

    /** Remove a stored CV (private or old public). Quietly does nothing if it is gone. */
    public static function delete(?string $stored): void
    {
        $path = self::existingPath($stored);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** The rule every CV link already followed: a blocked applicant's CV needs applicant-show-blocked-data. */
    public static function visible(bool $isBlocked): bool
    {
        return !$isBlocked || Gate::allows('applicant-show-blocked-data');
    }

    public static function url(int $applicantId, string $kind): string
    {
        return route('applicants.cv.download', ['id' => $applicantId, 'kind' => $kind]);
    }

    /**
     * The download icon for the applicant tables: a link to the download route when the file
     * is there and the user may see it, otherwise the grey "not available" icon.
     */
    public static function linkCell(?int $applicantId, ?string $stored, $isBlocked, string $kind, string $color,
                                    string $title = 'Download CV'): string
    {
        if ($applicantId && self::exists($stored) && self::visible((bool) $isBlocked)) {
            return '<a href="' . e(self::url($applicantId, $kind)) . '" title="' . e($title) . '" target="_blank" '
                . 'rel="noopener" class="text-decoration-none">'
                . '<iconify-icon icon="solar:download-square-bold" class="text-' . e($color) . ' fs-28"></iconify-icon></a>';
        }

        return '<button disabled title="CV Not Available" class="border-0 bg-transparent p-0">'
            . '<iconify-icon icon="solar:download-square-bold" class="text-grey fs-28"></iconify-icon></button>';
    }
}
