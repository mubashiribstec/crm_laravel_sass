<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Uploaded files kept outside the web root, with the older public copies still readable.
 *
 * A subclass names two folders: PRIVATE_PREFIX (relative to storage/app, where new files go,
 * under random names) and LEGACY_PREFIX (relative to public/, where older files still sit until
 * they are moved). Columns store the path relative to its root. Nothing outside those two
 * folders is ever resolved, ".." is refused, and a symlink that leads out of them isn't followed.
 */
abstract class PrivateFiles
{
    /** e.g. "private/resumes/" (storage/app/…). */
    public const PRIVATE_PREFIX = '';

    /** e.g. "uploads/" (public/…). */
    public const LEGACY_PREFIX = '';

    /** Save an upload privately under a random name; returns the value for the column. */
    public static function store(UploadedFile $file, ?string $extension = null): string
    {
        $path = static::newPrivatePath($extension ?? $file->getClientOriginalExtension());
        File::ensureDirectoryExists(dirname(storage_path('app/' . $path)), 0750);
        $file->move(dirname(storage_path('app/' . $path)), basename($path));

        return $path;
    }

    /** A new private path (YYYY/MM/<random>.<ext>) for a file that is about to be written. */
    public static function newPrivatePath(string $extension, ?string $yearMonth = null): string
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $extension));

        return static::PRIVATE_PREFIX . ($yearMonth ?? now()->format('Y/m')) . '/' . Str::lower(Str::random(40))
            . ($extension !== '' ? '.' . $extension : '');
    }

    /** The absolute path a stored value points at (whether or not the file exists), or null. */
    public static function fullPath(?string $stored): ?string
    {
        $stored = str_replace('\\', '/', trim((string) $stored));
        if ($stored === '' || str_contains($stored, '..') || str_contains($stored, "\0") || str_starts_with($stored, '/')) {
            return null;
        }
        if (str_starts_with($stored, static::PRIVATE_PREFIX)) {
            return storage_path('app/' . $stored);
        }
        if (str_starts_with($stored, static::LEGACY_PREFIX)) {
            return public_path($stored);
        }

        return null;
    }

    /** The absolute path of an existing file, or null (empty, missing, or outside the two folders). */
    public static function existingPath(?string $stored): ?string
    {
        $full = static::fullPath($stored);
        if ($full === null || !is_file($full)) {
            return null;
        }
        $root = realpath(str_starts_with(str_replace('\\', '/', trim((string) $stored)), static::PRIVATE_PREFIX)
            ? storage_path('app/' . rtrim(static::PRIVATE_PREFIX, '/'))
            : public_path(rtrim(static::LEGACY_PREFIX, '/')));
        $real = realpath($full);

        return $root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
    }

    public static function exists(?string $stored): bool
    {
        return static::existingPath($stored) !== null;
    }

    /** Remove a stored file (private or old public). Quietly does nothing if it is gone. */
    public static function delete(?string $stored): void
    {
        $path = static::existingPath($stored);
        if ($path !== null) {
            @unlink($path);
        }
    }
}
