<?php

namespace App\Support;

/**
 * Office and unit attachments (OfficeDocument, UnitDocument).
 *
 * New attachments go to storage/app/private/documents/YYYY/MM/<random>.<ext>, outside the web
 * root; they are only reachable through the permission-checked routes
 * (head-offices.documents.show, units.documents.show). Older ones still in public/uploads/docs/
 * are served by the same routes until `php artisan documents:move-to-private` moves them.
 */
class DocumentStorage extends PrivateFiles
{
    public const PRIVATE_PREFIX = 'private/documents/';
    public const LEGACY_PREFIX = 'uploads/docs/';

    /** Documents, spreadsheets and images: nothing a browser or the server would run. */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** The validation rule for one attachment (10 MB, as before). */
    public static function rule(): string
    {
        return 'file|extensions:' . implode(',', self::EXTENSIONS) . '|max:10240';
    }
}
