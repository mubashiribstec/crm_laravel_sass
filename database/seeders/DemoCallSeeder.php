<?php

namespace Database\Seeders;

use Horsefly\Applicant;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\Office;
use Horsefly\Sale;
use Horsefly\Unit;
use Horsefly\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for testing ONE AI call end to end (CRM -> Xplogent -> applicant).
 *
 * Creates a single OPEN vacancy and a single applicant who MATCHES it
 * (same job title, a few hundred metres away, not blocked, phone set),
 * so the vacancy shows up in "Agent Call (AI)" and the applicant appears
 * in the candidate list ready to call.
 *
 * The factory seeder's random data does NOT work for a call test: its
 * postcodes/lat-lng are random (the applicant is usually thousands of km
 * from the vacancy), its phone numbers are fake, and its job titles rarely
 * match the vacancy. This seeder fixes all of that for one pair.
 *
 * HOW TO USE
 * 1. Put the number you want the AI to call in the CRM's .env:
 *        DEMO_CALL_PHONE=+923203020204
 *    Use your OWN mobile (in E.164 format, with the + and country code),
 *    so you can answer and hear the AI. A UK number is best for a UK
 *    recruitment test; an international number also dials but costs more.
 * 2. Run:  php artisan db:seed --class=DemoCallSeeder --force
 * 3. Open the CRM -> Agent Call (AI), search for "Care Assistant",
 *    pick the "Demo Care Assistant - Leeds" vacancy, and the demo
 *    applicant will be listed. Click "Call with AI".
 *
 * Safe to run twice: it finds the demo vacancy/applicant and reuses them
 * instead of creating duplicates (and updates the phone if you changed it).
 */
class DemoCallSeeder extends Seeder
{
    public function run(): void
    {
        $phone = (string) env('DEMO_CALL_PHONE', '');

        if ($phone === '') {
            throw new \RuntimeException(
                'DEMO_CALL_PHONE is not set. Add it to the CRM .env, e.g. ' .
                'DEMO_CALL_PHONE=+923203020204 (your own mobile, E.164 format), ' .
                'then run this seeder again.'
            );
        }

        // --- Shared records (first existing one wins, else create) ---
        $user = User::first() ?? User::factory()->create();
        $office = Office::first() ?? Office::factory()->create();
        $unit = Unit::first() ?? Unit::factory()->create(['office_id' => $office->id]);
        $source = JobSource::first() ?? JobSource::factory()->create();

        // --- Job category + title (shared by the vacancy and the applicant) ---
        $category = JobCategory::firstOrCreate(
            ['name' => 'Health & Social Care'],
            ['is_active' => true]
        );

        $title = JobTitle::firstOrCreate(
            ['name' => 'Care Assistant', 'job_category_id' => $category->id],
            ['type' => 'full-time', 'description' => 'Demo job title for AI call testing.', 'is_active' => true]
        );

        // --- The vacancy: OPEN, in Leeds, with coordinates (required to be listed) ---
        // Leeds city centre: LS1 4DY, lat 53.7965, lng -1.5478.
        $sale = Sale::firstOrCreate(
            ['sale_postcode' => 'LS1 4DY', 'job_title_id' => $title->id],
            [
                'sale_uid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'office_id' => $office->id,
                'unit_id' => $unit->id,
                'job_category_id' => $category->id,
                'position_type' => 'full-time',
                'job_type' => 'permanent',
                'timing' => 'Days, 07:00-19:00',
                'salary' => '£12.50 per hour',
                'experience' => 'No experience needed, full training given.',
                'qualification' => 'Care Certificate (or willing to complete it).',
                'benefits' => 'Free parking, pension, paid holidays.',
                'job_description' => 'Demo vacancy for testing the AI call. Care Assistant role at a care home in Leeds city centre.',
                'lat' => 53.7965,
                'lng' => -1.5478,
                'is_on_hold' => 0,
                'is_re_open' => 0,
                'is_blacklist' => 0,
                'cv_limit' => 10,
                'status' => 1, // 1 = open (required by sales/open)
                'sale_notes' => 'Demo vacancy created by DemoCallSeeder for AI call testing.',
            ]
        );

        // Make sure the vacancy is open even if it already existed.
        if ($sale->status !== 1 || $sale->is_on_hold !== 0) {
            $sale->update(['status' => 1, 'is_on_hold' => 0]);
        }

        // --- The applicant: same job title, ~500 m from the vacancy, your number ---
        // Applicant postcode LS1 6AA, lat 53.8000, lng -1.5450 (about 500 m away).
        $applicant = Applicant::firstOrCreate(
            ['applicant_email' => 'demo.applicant@example.com'],
            [
                'applicant_uid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'job_source_id' => $source->id,
                'job_category_id' => $category->id,
                'job_title_id' => $title->id,
                'job_type' => 'full-time',
                'applicant_name' => 'Demo Applicant',
                'date' => now()->toDateString(),
                'applicant_phone' => $phone,
                'applicant_postcode' => 'LS1 6AA',
                'applicant_notes' => 'Demo applicant created by DemoCallSeeder for AI call testing. Please call and offer the Care Assistant role in Leeds.',
                'applicant_experience' => '2 years as a Care Assistant.',
                'lat' => 53.8000,
                'lng' => -1.5450,
                'is_blocked' => 0,
                'is_temp_not_interested' => 0,
                'is_callback_enable' => 0,
                'is_no_job' => 0,
                'is_no_response' => 0,
                'is_in_nurse_home' => 0,
                'is_circuit_busy' => 0,
                'is_cv_in_quality' => 0,
                'is_cv_in_quality_clear' => 0,
                'is_cv_sent' => 0,
                'is_cv_in_quality_reject' => 0,
                'is_interview_confirm' => 0,
                'is_interview_attend' => 0,
                'is_in_crm_request' => 0,
                'is_in_crm_reject' => 0,
                'is_in_crm_request_reject' => 0,
                'is_crm_request_confirm' => 0,
                'is_crm_interview_attended' => 0,
                'is_in_crm_start_date' => 0,
                'is_in_crm_invoice' => 0,
                'is_job_within_radius' => 1,
                'status' => 1, // 1 = active
                'paid_status' => 'pending',
            ]
        );

        // Keep the phone (and the match) current if you re-run with a new number.
        $applicant->update([
            'applicant_phone' => $phone,
            'job_title_id' => $title->id,
            'job_category_id' => $category->id,
            'lat' => 53.8000,
            'lng' => -1.5450,
            'is_blocked' => 0,
            'is_temp_not_interested' => 0,
            'is_no_job' => 0,
            'status' => 1,
        ]);

        $this->command->info('Demo call data ready.');
        $this->command->info("Vacancy: id {$sale->id}, Care Assistant, LS1 4DY (Leeds), status open.");
        $this->command->info("Applicant: id {$applicant->id}, Demo Applicant, phone {$phone}, LS1 6AA (about 500 m from the vacancy).");
        $this->command->info('Next: CRM -> Agent Call (AI) -> search "Care Assistant" -> pick the vacancy -> call the demo applicant.');
    }
}
