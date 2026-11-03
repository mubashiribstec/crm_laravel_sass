<?php

namespace Tests\Feature\Xplogent;

use App\Services\Xplogent\CvExcerpt;
use Horsefly\ApplicantNote;
use Horsefly\CallLog;
use Horsefly\ModuleNote;

class XplogentLookupTest extends XplogentTestCase
{
    public function test_matches_every_number_in_both_forms(): void
    {
        $a = $this->applicant(['applicant_phone' => '07700900123', 'applicant_phone_secondary' => '07700900456',
            'applicant_landline' => '01132000000']);
        foreach (['+447700900123' => 'mobile', '07700 900123' => 'mobile', '+447700900456' => 'secondary',
                     '0113 200 0000' => 'landline', '+441132000000' => 'landline'] as $phone => $kind) {
            $r = $this->signed('/api/xplogent/contact-lookup', ['phone' => $phone])->assertOk()->assertJson(['ok' => true]);
            $this->assertTrue($r->json('found'));
            $this->assertSame($a->id, $r->json('contact.applicant_id'));
            $this->assertSame($kind, $r->json('contact.phone_kind'), $phone);
        }
    }

    public function test_unknown_number_is_404_found_false(): void
    {
        $this->signed('/api/xplogent/contact-lookup', ['phone' => '+447700900999'])
            ->assertStatus(404)->assertJson(['ok' => true, 'found' => false]);
    }

    public function test_card_contents_limits_and_do_not_call(): void
    {
        $a = $this->applicant(['applicant_phone' => '07700900123', 'is_blocked' => true]);
        foreach (range(1, 7) as $i) {
            CallLog::create(['user_id' => $this->ai->id, 'applicant_id' => $a->id, 'direction' => 'outbound',
                'duration_seconds' => $i, 'called_at' => now()->subHours($i), 'source' => 'xplogent']);
        }
        ApplicantNote::create(['applicant_id' => $a->id, 'user_id' => $this->ai->id, 'details' => 'call me Friday',
            'moved_tab_to' => 'callback']);
        $c = $this->signed('/api/xplogent/contact-lookup', ['phone' => '+447700900123'])->assertOk()->json('contact');
        $this->assertTrue($c['do_not_call']);
        $this->assertTrue($c['flags']['blocked']);
        $this->assertCount(5, $c['calls']);
        $this->assertSame('Xplogent AI', $c['calls'][0]['by']);
        $this->assertSame('callback', $c['last_outcome']);
        $this->assertStringContainsString('call me Friday', $c['callback_note']);
        $this->assertSame('', $c['cv_excerpt']);                               // sharing off by default
        $this->assertArrayNotHasKey('applicant_email', $c);
    }

    /** X-04: free-text notes reach Xplogent without contact details or a date of birth. */
    public function test_notes_are_redacted_in_the_lookup_and_the_queue(): void
    {
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $leaky = 'Call her on 07700 900 123 or jane&#64;x.co.uk, DOB 12/03/1985, lives LS7 3AA. '
            . 'Callback booked 12/11/2026, keen on nights.';
        ModuleNote::create(['details' => $leaky, 'user_id' => $this->ai->id, 'module_noteable_id' => $a->id,
            'module_noteable_type' => 'Horsefly\Applicant', 'status' => 1]);
        ApplicantNote::create(['applicant_id' => $a->id, 'user_id' => $this->ai->id, 'details' => $leaky,
            'moved_tab_to' => 'callback']);

        $c = $this->signed('/api/xplogent/contact-lookup', ['phone' => '+447700900123'])->assertOk()->json('contact');
        $queued = $this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $this->sale()->id])
            ->assertOk()->json('candidates.0.last_note');
        foreach (['notes' => $c['notes'], 'callback_note' => $c['callback_note'], 'last_note' => $queued] as $field => $text) {
            foreach (['900 123', '900123', 'jane', 'x.co.uk', '1985', 'LS7 3AA'] as $leak) {
                $this->assertStringNotContainsString($leak, $text, "$field leaks $leak");
            }
            $this->assertStringContainsString('Callback booked 12/11/2026, keen on nights', $text, $field);
        }
    }

    public function test_cv_excerpt_is_redacted_and_capped_when_enabled(): void
    {
        config(['xplogent.share_cv_excerpt' => true]);
        $dir = public_path('uploads/resume/test');
        @mkdir($dir, 0755, true);
        file_put_contents("$dir/cv.txt", "Sarah Jones jane@x.co.uk 07700 900123 LS6 2AB DOB: 01/02/1990.\n"
            . 'Care assistant 2015 - 2019 at Oak House. ' . str_repeat('NVQ Level 3. ', 300));
        $this->applicant(['applicant_phone' => '07700900123', 'applicant_cv' => 'uploads/resume/test/cv.txt']);
        $cv = $this->signed('/api/xplogent/contact-lookup', ['phone' => '+447700900123'])->json('contact.cv_excerpt');
        $queued = $this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $this->sale()->id])
            ->assertOk()->json('candidates.0.cv_excerpt');
        $this->assertSame($cv, $queued);                                    // the queue carries the same excerpt
        @unlink("$dir/cv.txt");
        @rmdir($dir);
        $this->assertStringContainsString('Care assistant 2015 - 2019', $cv);
        foreach (['jane@x.co.uk', '900123', 'LS6 2AB', '1990'] as $leak) {
            $this->assertStringNotContainsString($leak, $cv);
        }
        $this->assertLessThanOrEqual(CvExcerpt::MAX, mb_strlen($cv));
    }
}
