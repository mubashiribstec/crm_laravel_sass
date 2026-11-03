<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_crm_interview_attended holds 0 = no, 1 = yes, 2 = pending (audit M-11). On MySQL/MariaDB a
 * "boolean" is tinyint(1) and already stores 2; the real bug was the model's boolean cast (fixed in
 * Horsefly\Applicant). The column is made an explicit tinyInteger so no other database coerces 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('applicants', 'is_crm_interview_attended')) {
            Schema::table('applicants', fn (Blueprint $t) => $t->tinyInteger('is_crm_interview_attended')
                ->default(0)->comment('0=not,1=yes,2=pending')->change());
        }
    }

    public function down(): void
    {
        // Kept as tinyInteger: a boolean column can't hold "pending" on every database.
    }
};
