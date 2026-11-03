<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGenderAndDobToApplicantsTable extends Migration
{
    public function up()
    {
        Schema::table('applicants', function (Blueprint $table) {
            if (!Schema::hasColumn('applicants', 'applicant_email_secondary')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->string('applicant_email_secondary')->nullable()->after('applicant_email');
            }
            if (!Schema::hasColumn('applicants', 'gender')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->enum('gender', ['m', 'f', 'u'])->nullable()->default('u')->after('applicant_email_secondary');
            }
            if (!Schema::hasColumn('applicants', 'dob')) {   // guard: a partial migrate or hand-made column (M-08)
                $table->date('dob')->nullable()->after('gender');
            }
        });
    }

    public function down()
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('applicant_email_secondary');
            $table->dropColumn('gender');
            $table->dropColumn('dob');
        });
    }
}
