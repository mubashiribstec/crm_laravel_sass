<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;

/**
 * Where applicant CVs live, and the one way to link to them.
 *
 * New uploads go to storage/app/private/resumes/YYYY/MM/<random>.<ext>, outside the web
 * root, and are only reachable through the signed-in, permission-checked download route
 * (applicants.cv.download). Older CVs still sit in public/uploads/resume/…; the route
 * serves those too until `php artisan cv:move-to-private` moves them.
 *
 * Columns store the path relative to its root: "private/resumes/…" (storage/app) or
 * "uploads/…" (public). Storing and resolving are PrivateFiles'.
 */
class CvStorage extends PrivateFiles
{
    public const PRIVATE_PREFIX = 'private/resumes/';
    public const LEGACY_PREFIX = 'uploads/';

    /** Download kinds and the applicant column each one reads. */
    public const KINDS = ['original' => 'applicant_cv', 'updated' => 'updated_cv'];

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
