<?php

namespace Tests\Feature\Xplogent;

use Horsefly\Applicant;
use Horsefly\ApplicantNote;
use Horsefly\ApplicantPivotSale;
use Horsefly\CallLog;
use Horsefly\CVNote;
use Horsefly\DialLock;
use Horsefly\ModuleNote;
use Horsefly\XplogentCallEvent;

class XplogentCallSummaryTest extends XplogentTestCase
{
    private int $callId = 1000;

    private function summary(Applicant $a, string $outcome, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->signed('/api/xplogent/call-summary', array_merge([
            'event' => 'call.completed', 'call_id' => ++$this->callId, 'test' => false, 'direction' => 'outbound',
            'from' => '+441132000000', 'to' => '+447700900123', 'applicant_id' => $a->id,
            'started_at' => '2026-10-28T10:15:22+00:00', 'duration_s' => 187, 'outcome' => $outcome,
            'summary' => 'Spoke to the candidate.', 'answers' => ['right_to_work' => 'British citizen'],
            'transcript' => [['speaker' => 'caller', 'text' => 'SECRET-TRANSCRIPT-WORDS']],
            'provider_call_id' => 'abc@xplogent',
        ], $extra));
    }

    public function test_every_outcome_writes_what_a_recruiter_would(): void
    {
        $sale = $this->sale();
        $expect = [
            'callback' => ['is_callback_enable' => true, 'tab' => 'callback'],
            'recruiter_callback' => ['is_callback_enable' => true, 'tab' => 'callback'],
            'no_job' => ['is_no_job' => true, 'tab' => 'no job'],
            'do_not_call' => ['is_blocked' => true, 'tab' => 'blocked'],
            'number_unobtainable' => ['is_circuit_busy' => true, 'tab' => 'circuit busy'],
            'interested' => ['is_temp_not_interested' => false, 'tab' => 'casual'],
            'wrong_person' => ['tab' => ''],
            'screen_incomplete' => ['tab' => ''],
        ];
        foreach ($expect as $outcome => $want) {
            $a = $this->applicant(['applicant_phone' => '07700900123']);
            $r = $this->summary($a, $outcome, ['sale_id' => $sale->id,
                'callback_at' => '2026-10-29T17:30:00+00:00'])->assertOk()->assertJson(['ok' => true]);
            $this->assertNotNull($r->json('call_log_id'));
            $a->refresh();
            $tab = $want['tab'];
            unset($want['tab']);
            foreach ($want as $flag => $value) {
                $this->assertSame($value, (bool) $a->{$flag}, "$outcome: $flag");
            }
            $note = ApplicantNote::where('applicant_id', $a->id)->latest('id')->first();
            $this->assertSame($tab, (string) $note->moved_tab_to, $outcome);
            $this->assertStringContainsString("AI call ($outcome)", $note->details);
            $this->assertStringContainsString('--- By: Xplogent AI Date:', $note->details);
            $this->assertStringNotContainsString('SECRET-TRANSCRIPT-WORDS', $note->details);
            $this->assertSame(1, ModuleNote::where('module_noteable_id', $a->id)->where('status', 1)->count());
        }
        $cb = ApplicantNote::where('details', 'like', 'RECRUITER CALLBACK:%')->first();
        $this->assertNotNull($cb);
        $this->assertStringContainsString('Callback Thu 29 Oct 17:30', $cb->details);   // Europe/London (GMT)
    }

    public function test_not_interested_is_recorded_for_that_vacancy_only(): void
    {
        $sale = $this->sale();
        $a = $this->applicant();
        $this->summary($a, 'not_interested', ['sale_id' => $sale->id])->assertOk();
        $this->assertTrue(ApplicantPivotSale::where(['applicant_id' => $a->id, 'sale_id' => $sale->id])->exists());
        $this->assertFalse((bool) $a->fresh()->is_temp_not_interested);          // not the global flag
        // Without a vacancy it is the recruiter's general "not interested".
        $b = $this->applicant();
        $this->summary($b, 'not_interested')->assertOk();
        $this->assertTrue((bool) $b->fresh()->is_temp_not_interested);
    }

    /** X-26(e): a second "not interested" for the same sale reuses its row; "interested" clears all of them. */
    public function test_not_interested_twice_keeps_one_row_and_interested_clears_old_duplicates(): void
    {
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $sale = $this->sale();
        $this->summary($a, 'not_interested', ['sale_id' => $sale->id])->assertOk();
        $this->summary($a, 'not_interested', ['sale_id' => $sale->id])->assertOk();
        $this->assertSame(1, ApplicantPivotSale::where(['applicant_id' => $a->id, 'sale_id' => $sale->id])->count());

        ApplicantPivotSale::create(['applicant_id' => $a->id, 'sale_id' => $sale->id]);     // a duplicate from older code
        $this->summary($a, 'interested', ['sale_id' => $sale->id])->assertOk();
        $this->assertSame(0, ApplicantPivotSale::where(['applicant_id' => $a->id, 'sale_id' => $sale->id])->count());
    }

    public function test_interested_reverts_a_not_interested_and_never_creates_cv_notes(): void
    {
        $sale = $this->sale();
        $a = $this->applicant(['is_temp_not_interested' => true]);
        ApplicantPivotSale::create(['applicant_id' => $a->id, 'sale_id' => $sale->id]);
        $this->summary($a, 'interested', ['sale_id' => $sale->id])->assertOk();
        $this->assertFalse(ApplicantPivotSale::where(['applicant_id' => $a->id, 'sale_id' => $sale->id])->exists());
        $this->assertFalse((bool) $a->fresh()->is_temp_not_interested);
        $this->assertSame(0, CVNote::count());
    }

    public function test_duplicates_and_test_calls_write_nothing(): void
    {
        $a = $this->applicant();
        $payload = ['call_id' => 77, 'outcome' => 'callback', 'applicant_id' => $a->id, 'to' => '+447700900123'];
        $this->signed('/api/xplogent/call-summary', $payload)->assertOk();
        $notes = ApplicantNote::count();
        $this->signed('/api/xplogent/call-summary', $payload)->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame($notes, ApplicantNote::count());
        $this->assertSame(1, CallLog::count());
        $this->signed('/api/xplogent/call-summary', ['call_id' => 78, 'test' => true, 'outcome' => 'callback',
            'applicant_id' => $a->id])->assertOk()->assertJson(['test' => true]);
        $this->assertSame(1, XplogentCallEvent::count());
    }

    /** X-07: the summary ends the AI's lock for that call, never a newer call's or a recruiter's. */
    public function test_a_summary_releases_that_calls_dial_lock_only(): void
    {
        $lock = fn (string $number, int $userId, string $name, $lockedAt) => DialLock::create([
            'phone_key' => DialLock::keyFor($number), 'full_number' => $number, 'user_id' => $userId,
            'user_name' => $name, 'lock_token' => 'tok-' . $number, 'call_count' => 1,
            'locked_at' => $lockedAt, 'expires_at' => now()->addMinutes(10)]);
        $started = now()->subMinutes(3);

        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $lock('07700900123', $this->ai->id, 'Xplogent AI', $started->copy()->subSeconds(5));
        $this->summary($a, 'callback', ['started_at' => $started->toIso8601String()])
            ->assertOk()->assertJson(['lock_released' => true]);
        $this->assertFalse(DialLock::where('phone_key', DialLock::keyFor('07700900123'))->first()->expires_at->isFuture());

        $b = $this->applicant(['applicant_phone' => '07700900124']);       // a newer AI call holds the lock
        $lock('07700900124', $this->ai->id, 'Xplogent AI', now());
        $this->summary($b, 'no_answer', ['to' => '+447700900124', 'started_at' => $started->toIso8601String()])
            ->assertOk()->assertJson(['lock_released' => false]);
        $c = $this->applicant(['applicant_phone' => '07700900125']);       // a recruiter holds it
        $recruiter = $this->staff('Tom Recruiter');
        $lock('07700900125', $recruiter->id, 'Tom Recruiter', $started->copy()->subMinute());
        $this->summary($c, 'callback', ['to' => '+447700900125', 'started_at' => $started->toIso8601String()])
            ->assertOk()->assertJson(['lock_released' => false]);
        $this->assertSame(2, DialLock::where('expires_at', '>', now())->count());
    }

    public function test_unanswered_attempts_log_calls_and_the_last_one_marks_no_response(): void
    {
        $sale = $this->sale();
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $this->summary($a, 'no_answer', ['sale_id' => $sale->id, 'final_attempt' => false])->assertOk();
        $this->summary($a, 'voicemail', ['sale_id' => $sale->id, 'final_attempt' => true])->assertOk();
        $this->assertSame(2, CallLog::where('applicant_id', $a->id)->where('source', 'xplogent')->count());
        $this->assertTrue((bool) $a->fresh()->is_no_response);
        $this->assertSame(1, ApplicantNote::where('applicant_id', $a->id)->count());   // only the final attempt
        $log = CallLog::latest('id')->first();
        $this->assertSame('07700900123', $log->caller_number);                          // national form
        $this->assertSame('abc@xplogent', $log->sip_call_id);
        $this->assertSame('SECRET-TRANSCRIPT-WORDS', XplogentCallEvent::latest('id')->first()->payload['transcript'][0]['text']);
    }

    public function test_older_builds_without_ids_are_matched_by_phone_and_new_emails_noted(): void
    {
        $a = $this->applicant(['applicant_phone' => '07700900123', 'applicant_email' => 'old@example.org']);
        $this->signed('/api/xplogent/call-summary', ['call_id' => 501, 'outcome' => 'callback', 'direction' => 'outbound',
            'to' => '+447700900123', 'email_confirmed' => 'new@example.org', 'summary' => 'Call back later.'])->assertOk();
        $note = ApplicantNote::where('applicant_id', $a->id)->first();
        $this->assertStringContainsString('Candidate gave email new@example.org', $note->details);
        $this->assertSame('old@example.org', $a->fresh()->applicant_email);              // never overwritten
    }
}
