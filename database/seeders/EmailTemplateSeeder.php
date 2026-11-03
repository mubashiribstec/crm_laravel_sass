<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The vacancy email the CRM and Xplogent send (send_job_vacancy_details), as an INACTIVE
 * placeholder for a fresh install (audit S-05): an admin writes the wording and switches it on
 * under Settings → Email templates. An existing template is never touched.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('email_templates')->where('slug', 'send_job_vacancy_details')->exists()) {
            return;
        }
        DB::table('email_templates')->insert([
            'title' => 'Send Job Vacancy Details',
            'slug' => 'send_job_vacancy_details',
            'subject' => 'A job near you',
            'from_email' => null,
            'is_active' => 0,
            'template' => "Hello,\n\n(agent_name) here. Thank you for your time on the phone.\n\n"
                . "Role: (job_category) at (unit_name), (location)\nPay: (salary)\nHours: (job_type), (timing)\n"
                . "Experience: (experience)\nQualifications: (qualification)\n\nReply to this email if you would like to apply.",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
