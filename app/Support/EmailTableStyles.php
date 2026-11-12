<?php

namespace App\Support;

/**
 * Writes the look of Bootstrap's table-bordered directly onto the table, because email
 * clients don't have the CRM's CSS. Inline styles already on an element still win.
 */
class EmailTableStyles
{
    private const TABLE = 'width:100%;max-width:620px;border-collapse:collapse;border:1px solid #d9dee5;font-family:Arial,Helvetica,sans-serif;font-size:14px;';
    private const CELL = 'border:1px solid #d9dee5;padding:8px 12px;vertical-align:top;text-align:left;';
    private const PARAGRAPH = 'margin:0;';

    public static function inline(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return preg_replace_callback(
            '/<table\b[^>]*\btable-bordered\b[^>]*>.*?<\/table>/is',
            fn ($matches) => self::styleTable($matches[0]),
            $html
        ) ?? $html;
    }

    private static function styleTable(string $table): string
    {
        return preg_replace_callback(
            '/<(table|td|th|p)\b[^>]*>/i',
            fn ($matches) => self::addStyle($matches[0], match (strtolower($matches[1])) {
                'table' => self::TABLE,
                'p' => self::PARAGRAPH,
                default => self::CELL,
            }),
            $table
        ) ?? $table;
    }

    private static function addStyle(string $tag, string $css): string
    {
        $merged = preg_replace('/\bstyle\s*=\s*(["\'])/i', 'style=$1' . $css, $tag, 1, $count);

        if ($count > 0) {
            return $merged;
        }

        return preg_replace('/\s*\/?>$/', ' style="' . $css . '">', $tag, 1);
    }
}
