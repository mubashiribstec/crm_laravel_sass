<?php

namespace App\Support;

use Horsefly\Sale;

/**
 * Decides whether the Send Email button is restricted for a sale.
 */
class SaleEmailRestriction
{
    public const JOB_SOURCE_ID = 10;
    public const KEYWORD = 'hayaibu';
    public const TEXT_COLUMNS = [
        'sale_notes',
        'experience',
        'qualification',
        'salary',
        'timing',
        'benefits',
        'job_description',
    ];
    public const TITLE = 'Email is restricted for this sale';

    public static function applies(Sale $sale): bool
    {
        if ((int) $sale->job_source_id === self::JOB_SOURCE_ID) {
            return true;
        }

        foreach (self::TEXT_COLUMNS as $column) {
            // These columns hold editor HTML, so tags are removed before matching.
            if (stripos(strip_tags((string) $sale->{$column}), self::KEYWORD) !== false) {
                return true;
            }
        }

        return false;
    }

    /** The disabled replacement for the Send Email item in the action dropdowns. */
    public static function disabledDropdownItem(): string
    {
        return '<li><a href="javascript:void(0);" class="dropdown-item disabled" aria-disabled="true" tabindex="-1">'
            . 'Send Email <small class="text-danger">(Restricted)</small></a></li>';
    }
}
