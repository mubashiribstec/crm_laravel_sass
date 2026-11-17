<?php

namespace App\Support;

/**
 * Where the Send CV synopsis forms (Word files with the applicant's name, visa status, DBS
 * convictions and expected salary) live.
 *
 * New forms go to storage/app/private/synopsis-forms/YYYY/MM/<random>.docx, outside the web root,
 * and are only reachable through the signed-in, permission-checked download routes
 * (applicants.draft-file.download / .auto-download). Forms written before this sit in
 * public/uploads/synopsisForms/… (or the older public/uploads/drafts/…); the routes serve those
 * too until `php artisan synopsis:move-to-private` moves them.
 */
class SynopsisFormStorage extends PrivateFiles
{
    public const PRIVATE_PREFIX = 'private/synopsis-forms/';
    public const LEGACY_PREFIX = 'uploads/';

    /** The only public folders a synopsis form was ever written to. */
    public const LEGACY_FOLDERS = ['uploads/synopsisForms/', 'uploads/drafts/'];

    public static function fullPath(?string $stored): ?string
    {
        $clean = str_replace('\\', '/', trim((string) $stored));
        if (str_starts_with($clean, static::LEGACY_PREFIX)) {
            $inLegacyFolder = false;
            foreach (self::LEGACY_FOLDERS as $folder) {
                $inLegacyFolder = $inLegacyFolder || str_starts_with($clean, $folder);
            }
            if (!$inLegacyFolder) {
                return null;            // never another public upload (CVs, attachments…)
            }
        }

        return parent::fullPath($stored);
    }

    public static function isLegacy(?string $stored): bool
    {
        return str_starts_with(str_replace('\\', '/', trim((string) $stored)), static::LEGACY_PREFIX);
    }

    /** A readable name for the download (the stored name is random). */
    public static function downloadName(?string $applicantName, $createdAt, int $id): string
    {
        $name = \Illuminate\Support\Str::slug((string) $applicantName) ?: 'applicant';
        $date = $createdAt ? \Carbon\Carbon::parse($createdAt)->format('Y-m-d') : now()->format('Y-m-d');

        return "{$name}_synopsis-form_{$date}_{$id}.docx";
    }
}
