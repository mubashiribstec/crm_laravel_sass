<?php

namespace Database\Seeders;

use App\Support\Xplogent;
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
 * Test applicant whose number is a PBX extension, so the AI can call the
 * user's own extension and he can answer it to test the call end to end
 * (CRM -> Xplogent -> PBX extension line -> his phone).
 *
 * Creates ONE test applicant matched to the OPEN Care Assistant vacancy in
 * Leeds (the same vacancy DemoCallSeeder creates: LS1 4DY, lat 53.7965,
 * lng -1.5478). If that vacancy does not exist yet, it is created here.
 * The applicant sits ~500 m from the vacancy, shares its job title and
 * job category, and has every blocking flag cleared, so it passes the
 * Agent-call queue's radius / title / category / status filters.
 *
 * HOW TO USE
 * 1. Put the extension you want the AI to call in the CRM's .env:
 *        TEST_APPLICANT_EXTENSION=505
 *    Use YOUR OWN extension (the one on your FreePBX that rings your phone),
 *    so you can answer and hear the AI. A short extension (e.g. "505") or a
 *    full number both work as the stored value; see the caveat below.
 * 2. Run:  php artisan db:seed --class=TestExtensionApplicantSeeder --force
 * 3. Open the CRM -> Agent Call (AI), search for "Care Assistant",
 *    pick the Leeds vacancy, and the test applicant will be listed.
 *    Click "Call with AI".
 *
 * Safe to run twice: it finds the vacancy and the test applicant and reuses
 * them instead of creating duplicates (and updates the extension if you
 * changed it).
 *
 * ── CAVEAT: a bare extension does NOT yet pass the CRM's phone check ──
 * The Agent-call queue and the dial step only accept numbers that
 * App\Support\Xplogent::toE164() can turn into E.164, and toE164() is
 * UK-only (it only accepts 01/02/03/07 numbers, turning them into +44…).
 * A bare extension such as "505" returns null from toE164(), so the
 * applicant is left out of the queue with reason "no_phone", and dialling
 * returns 422 "no number Xplogent can dial" — the call never reaches
 * Xplogent. The same UK-only limit also blocks a non-UK mobile (e.g. +92):
 * toE164() returns null for it too, so the DemoCallSeeder's pair only works
 * with a UK number until this is fixed.
 *
 * The fix (CRM side, one function): extend toE164()
 * (app/Support/Xplogent.php, toE164()) to also pass through (a) a valid
 * international E.164 number as-is (e.g. +92…), and (b) a short PBX
 * extension (2–15 chars of digits / * / # / +) as-is, so
 * Directory::bestPhone() (app/Services/Xplogent/Directory.php) can return
 * it. Xplogent already accepts short extensions on its side: its dial
 * accepts them when the call goes via the PBX extension line
 * (src/xplogent/voip/service.py, dial(): "PBX extensions are short:
 * 100, *43, 2001"). So once the CRM passes the extension through, the
 * call will be dialled on the extension line — provided the Xplogent
 * telephony mode is "extension" (not Telnyx), and the FreePBX routes the
 * call to that extension (the extension must exist and be reachable from
 * the Xplogent extension line).
 *
 * NO-CODE-CHANGE ALTERNATIVE: if you do not want to change toE164(), put
 * the extension's DID (its full UK number, e.g. the number that rings the
 * extension from outside) in TEST_APPLICANT_EXTENSION instead of the short
 * extension. A full UK number passes toE164(), so the applicant appears in
 * the queue and the call goes through as a normal UK call.
 *
 * This seeder prints a warning when the value you set will not pass
 * toE164() yet, so you know immediately whether the applicant will be
 * callable or whether the fix above is still needed.
 */
class TestExtensionApplicantSeeder extends Seeder
{
    public function run(): void
    {
        $extension = trim((string) env('TEST_APPLICANT_EXTENSION', ''));

        if ($extension === '') {
            throw new \RuntimeException(
                'TEST_APPLICANT_EXTENSION is not set. Add it to the CRM .env, e.g. ' .
                'TEST_APPLICANT_EXTENSION=505 (your own PBX extension, the one that rings your phone), ' .
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

        // --- The vacancy: reuse DemoCallSeeder's open Leeds vacancy if it exists, else create it ---
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
                'sale_notes' => 'Demo vacancy created for AI call testing.',
            ]
        );

        // Make sure the vacancy is open even if it already existed.
        if ($sale->status !== 1 || $sale->is_on_hold !== 0) {
            $sale->update(['status' => 1, 'is_on_hold' => 0]);
        }

        // --- The test applicant: same job title/category, ~500 m from the vacancy, extension as phone ---
        // Applicant postcode LS1 6AA, lat 53.8000, lng -1.5450 (about 500 m away).
        $applicant = Applicant::firstOrCreate(
            ['applicant_email' => 'test.extension@example.com'],
            [
                'applicant_uid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'job_source_id' => $source->id,
                'job_category_id' => $category->id,
                'job_title_id' => $title->id,
                'job_type' => 'full-time',
                'applicant_name' => 'Test Extension Applicant',
                'date' => now()->toDateString(),
                'applicant_phone' => $extension,
                'applicant_postcode' => 'LS1 6AA',
                'applicant_notes' => 'Test applicant for AI call testing. Phone is the owner\'s PBX extension — the AI should call the extension and the owner answers it.',
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

        // Keep the extension (and the match) current if you re-run with a new value.
        $applicant->update([
            'applicant_phone' => $extension,
            'job_title_id' => $title->id,
            'job_category_id' => $category->id,
            'lat' => 53.8000,
            'lng' => -1.5450,
            'is_blocked' => 0,
            'is_temp_not_interested' => 0,
            'is_no_job' => 0,
            'status' => 1,
        ]);

        $this->command->info('Test extension applicant ready.');
        $this->command->info("Vacancy: id {$sale->id}, Care Assistant, LS1 4DY (Leeds), status open.");
        $this->command->info("Applicant: id {$applicant->id}, Test Extension Applicant, extension {$extension}, LS1 6AA (about 500 m from the vacancy).");

        // Self-check: will the queue accept this number as it stands?
        if (Xplogent::toE164($extension) === null) {
            $this->command->warn("WARNING: '{$extension}' does not pass the CRM's phone check (Xplogent::toE164() is UK-only).");
            $this->command->warn('This applicant will be left out of the Agent-call queue ("no_phone") and dialling will fail');
            $this->command->warn('with "no number Xplogent can dial" until toE164() is extended to pass extensions through.');
            $this->command->warn('See the caveat at the top of this file for the fix, or use the extension\'s DID (full UK number) instead.');
        } else {
            $this->command->info("Phone check passed: '{$extension}' normalises to " . Xplogent::toE164($extension) . '.');
        }

        $this->command->info('Next: CRM -> Agent Call (AI) -> search "Care Assistant" -> pick the vacancy -> call the test applicant.');
    }
}
