<?php

namespace Tests\Feature\Xplogent;

use Horsefly\EmailTemplate;
use Horsefly\ModuleNote;
use Horsefly\SentEmail;

class XplogentJobDetailsTest extends XplogentTestCase
{
    private function template(): void
    {
        EmailTemplate::create(['slug' => 'send_job_vacancy_details', 'title' => 'Send Job Vacancy Details',
            'subject' => 'A job near you', 'from_email' => 'jobs@example.org', 'is_active' => 1,
            'template' => "Hi, (agent_name) here. (job_category) at (unit_name), (salary), (job_type), (timing)."]);
    }

    public function test_queues_the_filled_template_and_limits_three_a_day(): void
    {
        $this->template();
        $sale = $this->sale();
        $a = $this->applicant();
        $body = ['applicant_id' => $a->id, 'sale_id' => $sale->id, 'email' => 'sarah@example.org', 'call_id' => 9];
        $r = $this->signed('/api/xplogent/send-job-details', $body)->assertOk()->assertJson(['ok' => true]);
        $sent = SentEmail::find($r->json('sent_email_id'));
        $this->assertSame('0', (string) $sent->status);                                  // queued for emails:send-bulk
        $this->assertSame('jobs@example.org', $sent->sent_from);
        $this->assertSame($this->ai->id, $sent->user_id);
        $this->assertStringContainsString('Xplogent AI here. Care at Oak House, £12.60 per hour, Full time, Days.', $sent->template);
        $this->signed('/api/xplogent/send-job-details', ['call_id' => 10] + $body)->assertOk();     // other calls
        $this->signed('/api/xplogent/send-job-details', ['call_id' => 11] + $body)->assertOk();
        $this->signed('/api/xplogent/send-job-details', ['call_id' => 12] + $body)->assertStatus(429);
    }

    /** X-08: one email per call, candidate and vacancy; a repeat is a no-op. */
    public function test_the_same_call_queues_the_email_once_and_the_call_note_says_where_it_went(): void
    {
        $this->template();
        $sale = $this->sale();
        $a = $this->applicant(['applicant_email' => 'sarah@example.org']);
        $body = ['applicant_id' => $a->id, 'sale_id' => $sale->id, 'email' => 'Sarah.New@example.org', 'call_id' => 77];
        $first = $this->signed('/api/xplogent/send-job-details', $body)->assertOk()->json('sent_email_id');
        $this->signed('/api/xplogent/send-job-details', $body)->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => true, 'sent_email_id' => $first]);
        $this->assertSame(1, SentEmail::count());
        $this->assertSame(77, (int) SentEmail::find($first)->xplogent_call_id);

        $this->signed('/api/xplogent/call-summary', ['call_id' => 77, 'outcome' => 'interested', 'applicant_id' => $a->id,
            'sale_id' => $sale->id, 'to' => '+447700901001', 'summary' => 'Keen on the role'])->assertOk();
        $note = ModuleNote::where('module_noteable_id', $a->id)->where('status', 1)->value('details');
        $this->assertStringContainsString('Vacancy details emailed to sarah.new@example.org.', $note);
    }

    /** X-08: no email about a vacancy that can't be called (closed, on hold, blacklisted, full). */
    public function test_a_vacancy_that_cannot_be_called_gets_no_email(): void
    {
        $this->template();
        foreach ([['status' => 0], ['is_on_hold' => 1], ['is_blacklist' => true]] as $state) {
            $this->signed('/api/xplogent/send-job-details', ['applicant_id' => $this->applicant()->id,
                'sale_id' => $this->sale($state)->id, 'email' => 'x@example.org', 'call_id' => 5])
                ->assertStatus(409)->assertJson(['error' => 'sale_not_callable']);
        }
        $this->assertSame(0, SentEmail::count());
    }

    public function test_blocked_applicants_and_bad_emails_are_refused(): void
    {
        $this->template();
        $sale = $this->sale();
        $blocked = $this->applicant(['is_blocked' => true]);
        $this->signed('/api/xplogent/send-job-details', ['applicant_id' => $blocked->id, 'sale_id' => $sale->id,
            'email' => 'x@example.org'])->assertStatus(409)->assertJson(['error' => 'blocked']);
        $this->signed('/api/xplogent/send-job-details', ['applicant_id' => $this->applicant()->id,
            'sale_id' => $sale->id, 'email' => 'not an email'])->assertStatus(422);
        $this->assertSame(0, SentEmail::count());
    }
}
