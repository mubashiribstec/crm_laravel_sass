<?php

namespace Tests\Feature\Xplogent;

use Horsefly\EmailTemplate;
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
        $r = $this->signed('/api/xplogent/send-job-details', $body)->assertOk();
        $sent = SentEmail::find($r->json('sent_email_id'));
        $this->assertSame('0', (string) $sent->status);                                  // queued for emails:send-bulk
        $this->assertSame('jobs@example.org', $sent->sent_from);
        $this->assertSame($this->ai->id, $sent->user_id);
        $this->assertStringContainsString('Xplogent AI here. Care at Oak House, £12.60 per hour, Full time, Days.', $sent->template);
        $this->signed('/api/xplogent/send-job-details', $body)->assertOk();
        $this->signed('/api/xplogent/send-job-details', $body)->assertOk();
        $this->signed('/api/xplogent/send-job-details', $body)->assertStatus(429);
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
