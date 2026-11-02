<?php

namespace Tests\Feature\Xplogent;

use App\Http\Controllers\CommunicationController;
use Horsefly\ApplicantNote;
use Horsefly\EmailTemplate;
use Horsefly\ModuleNote;

/** The recruiter screens behave as before the shared services were extracted. */
class XplogentRegressionTest extends XplogentTestCase
{
    public function test_store_short_notes_writes_the_same_flags_and_note(): void
    {
        $recruiter = $this->staff('Jane Recruiter', 'applicant-add-note');
        $cases = [
            'callback' => ['is_callback_enable' => 1, 'is_temp_not_interested' => 0, 'tab' => 'callback'],
            'not_interested' => ['is_temp_not_interested' => 1, 'is_callback_enable' => 0, 'tab' => 'not interested'],
            'blocked' => ['is_blocked' => 1, 'tab' => 'blocked'],
            'no_job' => ['is_no_job' => 1, 'tab' => 'no job'],
            'no_response' => ['is_no_response' => 1, 'tab' => 'no response'],
            'circuit_busy' => ['is_circuit_busy' => 1, 'tab' => 'circuit busy'],
            'casual' => ['is_blocked' => 0, 'is_callback_enable' => 0, 'tab' => 'casual'],
        ];
        foreach ($cases as $reason => $want) {
            $a = $this->applicant();
            $this->actingAs($recruiter)->post('/storeShortNotes', ['applicant_id' => $a->id,
                'details' => "spoke ($reason)", 'reason' => $reason])->assertRedirect();
            $a->refresh();
            $tab = $want['tab'];
            unset($want['tab']);
            foreach ($want as $flag => $value) {
                $this->assertSame($value, (int) $a->{$flag}, "$reason: $flag");
            }
            $this->assertStringStartsWith("spoke ($reason) --- By: Jane Recruiter Date: ", $a->applicant_notes);
            $note = ApplicantNote::where('applicant_id', $a->id)->first();
            $this->assertSame($tab, $note->moved_tab_to);
            $this->assertSame(md5($note->id), $note->note_uid);
            $this->assertSame(1, ModuleNote::where('module_noteable_id', $a->id)->where('status', 1)->count());
        }
    }

    public function test_vacancy_email_template_is_filled_as_before(): void
    {
        EmailTemplate::create(['slug' => 'send_job_vacancy_details', 'title' => 'Send Job Vacancy Details',
            'subject' => 'A job near you', 'from_email' => 'jobs@example.org', 'is_active' => 1,
            'template' => '(agent_name): (job_category) / (job_title) at (unit_name), (salary), (location)']);
        $sale = $this->sale();
        $recruiter = $this->staff('Jane Recruiter', 'email-index');
        $r = $this->actingAs($recruiter)->get('/send-email-to-applicant?sale_id=' . $sale->id)->assertOk();
        $this->assertSame('A job near you', $r->viewData('subject'));
        $this->assertStringContainsString('Jane Recruiter: Care', $r->viewData('formattedMessage'));
        $this->assertStringContainsString('at Oak House, £12.60 per hour', $r->viewData('formattedMessage'));
    }

    public function test_bulk_vacancy_email_skips_blocked_no_job_and_callback_applicants(): void
    {
        $sale = $this->sale();
        $this->applicant(['applicant_email' => 'ok@test.co.uk']);
        $this->applicant(['applicant_email' => 'blocked@test.co.uk', 'is_blocked' => true]);
        $this->applicant(['applicant_email' => 'nojob@test.co.uk', 'is_no_job' => true]);
        $this->applicant(['applicant_email' => 'cb@test.co.uk', 'is_callback_enable' => true]);
        $this->applicant(['applicant_email' => 'inactive@test.co.uk', 'status' => 0]);
        $emails = app(CommunicationController::class)->distance($sale->lat, $sale->lng, 15,
            $sale->job_title_id, $sale->job_category_id);
        $this->assertSame(['ok@test.co.uk'], collect($emails)->values()->all());
    }
}
