<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The CRM's "Last 7 days / 21 days / 3, 6, 9 months / other" applicant buckets (by
 * applicants.updated_at), shared by the Resources tabs and the dashboard's counts so the
 * two always agree. They are deliberately offset, not literal "last N days":
 *   last-7-days   today-16 … today
 *   last-21-days  the 21 days before that (ending today-16)
 *   last-3-months the 3 months ending today-37
 *   last-6-months the 6 months ending 3 months + 37 days ago
 *   last-9-months the 9 months ending 9 months + 37 days ago
 *   other         older than 19 months + 7 days
 */
final class ApplicantAgeWindows
{
    public const KEYS = ['last-7-days', 'last-21-days', 'last-3-months', 'last-6-months', 'last-9-months', 'other'];

    /** Restrict $query to one bucket ($column e.g. "applicants.updated_at"); unknown keys mean last-7-days. */
    public static function apply($query, string $column, ?string $key, ?Carbon $today = null)
    {
        $now = ($today ?? Carbon::today())->copy()->startOfDay();

        switch ($key) {
            case 'last-21-days':
                $end = $now->copy()->subDays(16);
                return $query->whereBetween($column, [$end->copy()->subDays(21)->startOfDay(), $end->endOfDay()]);
            case 'last-3-months':
                $end = $now->copy()->subDays(37);
                return $query->whereBetween($column, [$end->copy()->subMonths(3)->startOfDay(), $end->endOfDay()]);
            case 'last-6-months':
                $end = $now->copy()->subMonths(3)->subDays(37);
                return $query->whereBetween($column, [$end->copy()->subMonths(6)->startOfDay(), $end->endOfDay()]);
            case 'last-9-months':
                $end = $now->copy()->subMonths(9)->subDays(37);
                return $query->whereBetween($column, [$end->copy()->subMonths(9)->startOfDay(), $end->endOfDay()]);
            case 'other':
                return $query->where($column, '<', $now->copy()->subMonths(19)->subDays(7));
            case 'last-7-days':
            default:
                return $query->whereBetween($column, [$now->copy()->subDays(16)->startOfDay(), $now->copy()->endOfDay()]);
        }
    }
}
