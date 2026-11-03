<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * applicants has both applicant_email_secodary (a typo, read by nothing) and
 * applicant_email_secondary (the one the app uses) (audit M-03). Any address only in the typo
 * column is copied across first; if a row has two different addresses, this stops rather than
 * lose one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('applicants', 'applicant_email_secodary')) {
            return;
        }
        $filled = fn ($q, $c) => $q->whereNotNull($c)->where($c, '<>', '');
        $conflicts = DB::table('applicants')
            ->where(fn ($q) => $filled($q, 'applicant_email_secodary'))
            ->where(fn ($q) => $filled($q, 'applicant_email_secondary'))
            ->whereColumn('applicant_email_secodary', '<>', 'applicant_email_secondary')
            ->pluck('id');
        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException($conflicts->count() . ' applicant(s) have different addresses in '
                . 'applicant_email_secodary and applicant_email_secondary (ids ' . $conflicts->take(10)->implode(', ')
                . '). Decide which to keep, then run php artisan migrate again.');
        }
        DB::table('applicants')
            ->where(fn ($q) => $filled($q, 'applicant_email_secodary'))
            ->where(fn ($q) => $q->whereNull('applicant_email_secondary')->orWhere('applicant_email_secondary', ''))
            ->update(['applicant_email_secondary' => DB::raw('applicant_email_secodary')]);

        Schema::table('applicants', fn (Blueprint $t) => $t->dropColumn('applicant_email_secodary'));
    }

    public function down(): void
    {
        if (!Schema::hasColumn('applicants', 'applicant_email_secodary')) {
            Schema::table('applicants', fn (Blueprint $t) => $t->string('applicant_email_secodary', 255)->nullable());
        }
    }
};
