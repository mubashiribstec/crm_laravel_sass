<?php

namespace Tests\Feature\Xplogent;

use App\Support\Xplogent;
use Horsefly\CallLog;
use Horsefly\DialLock;
use Horsefly\User;
use Horsefly\XplogentCallEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

class XplogentAgentCallPageTest extends XplogentTestCase
{
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'xplogent-view-transcript', 'guard_name' => 'web']);
        $this->staff = $this->staff('Jane Recruiter', 'xplogent-agent-call');
        config(['xplogent.base_url' => 'http://xplogent.lan:8765']);
    }

    public function test_the_page_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/xplogent/agent-call')->assertForbidden();
        $this->actingAs($this->staff)->get('/xplogent/agent-call')->assertOk()->assertSee('Agent Call (AI)');
    }

    public function test_queue_masks_phone_numbers(): void
    {
        $sale = $this->sale();
        $this->applicant(['applicant_phone' => '07700900123']);
        $c = $this->actingAs($this->staff)->getJson('/xplogent/agent-call/queue?sale_id=' . $sale->id)
            ->assertOk()->json('candidates.0');
        $this->assertArrayNotHasKey('phone_e164', $c);
        $this->assertStringNotContainsString('900123', $c['phone_display']);
    }

    public function test_dial_sends_a_signed_request_with_the_brief(): void
    {
        Http::fake(['xplogent.lan:8765/*' => Http::response(['ok' => true, 'call' => ['id' => 42, 'state' => 'dialling']])]);
        $sale = $this->sale();
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $this->actingAs($this->staff)->postJson('/xplogent/agent-call/dial', ['applicant_id' => $a->id,
            'sale_id' => $sale->id])->assertOk()->assertJson(['ok' => true, 'call' => ['id' => 42]]);
        Http::assertSent(function (Request $r) use ($a, $sale) {
            $body = $r->body();
            $ts = $r->header('X-Xplogent-Timestamp')[0];

            return $r->url() === 'http://xplogent.lan:8765/voip/crm/dial'
                && $r->header('X-Xplogent-Signature-V2')[0] === Xplogent::sign($body, self::TOKEN, $ts)
                && $r['phone'] === '+447700900123' && $r['applicant_id'] === $a->id && $r['sale_id'] === $sale->id
                && $r['requested_by'] === 'Jane Recruiter'
                && $r['brief']['sale']['salary'] === '£12.60 per hour'
                && $r['brief']['candidate']['first_name'] === 'Sarah'
                && !str_contains($body, 'applicant_email') && !str_contains($body, '@example.org');
        });
    }

    public function test_a_locked_or_blocked_candidate_is_not_dialled(): void
    {
        Http::fake();
        $sale = $this->sale();
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        DialLock::create(['phone_key' => DialLock::keyFor('07700900123'), 'full_number' => '07700900123',
            'user_id' => $this->staff->id, 'user_name' => 'Tom Recruiter', 'call_count' => 1,
            'locked_at' => now(), 'expires_at' => now()->addMinutes(5)]);
        $this->actingAs($this->staff)->postJson('/xplogent/agent-call/dial', ['applicant_id' => $a->id,
            'sale_id' => $sale->id])->assertStatus(409)->assertJson(['locked_by' => 'Tom Recruiter']);
        $b = $this->applicant(['is_blocked' => true]);
        $this->actingAs($this->staff)->postJson('/xplogent/agent-call/dial', ['applicant_id' => $b->id,
            'sale_id' => $sale->id])->assertStatus(409);
        Http::assertNothingSent();
    }

    /** X-05: Call with AI needs the AI user, or the result would have nowhere to go. */
    public function test_dial_needs_the_ai_user(): void
    {
        Http::fake();
        $sale = $this->sale();
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        config(['xplogent.ai_user_id' => null]);
        $this->actingAs($this->staff)->postJson('/xplogent/agent-call/dial', ['applicant_id' => $a->id,
            'sale_id' => $sale->id])->assertStatus(503)->assertJson(['ok' => false])
            ->assertSee('xplogent:make-ai-user');
        Http::assertNothingSent();
    }

    public function test_transcripts_need_their_own_permission(): void
    {
        $a = $this->applicant();
        $log = CallLog::create(['user_id' => $this->ai->id, 'applicant_id' => $a->id, 'direction' => 'outbound',
            'duration_seconds' => 60, 'called_at' => now(), 'source' => 'xplogent']);
        XplogentCallEvent::create(['call_id' => 5, 'applicant_id' => $a->id, 'call_log_id' => $log->id,
            'outcome' => 'callback', 'payload' => ['outcome' => 'callback', 'summary' => 'Call back Friday',
                'transcript' => [['speaker' => 'caller', 'text' => 'hello']]]]);
        $row = $this->actingAs($this->staff)->getJson('/xplogent/agent-call/attempts?applicant_id=' . $a->id)
            ->assertOk()->json('attempts.0');
        $this->assertSame('Call back Friday', $row['summary']);
        $this->assertNull($row['transcript']);
        $this->staff->givePermissionTo('xplogent-view-transcript');
        $row = $this->actingAs($this->staff->fresh())->getJson('/xplogent/agent-call/attempts?applicant_id=' . $a->id)
            ->json('attempts.0');
        $this->assertSame('hello', $row['transcript'][0]['text']);
    }
}
