<?php

namespace Tests\Feature\Xplogent;

use App\Support\Xplogent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The CRM -> Xplogent direction: php artisan xplogent:check and the page's Test connection. */
class XplogentCheckTest extends XplogentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['xplogent.base_url' => 'http://192.168.1.30:8765']);
    }

    public function test_a_signed_test_request_dials_nothing_and_reports_the_agent_state(): void
    {
        Http::fake(['192.168.1.30:8765/*' => Http::response(['ok' => true, 'test' => true, 'agent_enabled' => false,
            'telephony' => 'extension'])]);
        $this->artisan('xplogent:check')
            ->expectsOutputToContain('http://192.168.1.30:8765')
            ->expectsOutputToContain('accepted the token')
            ->expectsOutputToContain('switched off')
            ->assertExitCode(0);
        Http::assertSent(function (Request $r) {
            $ts = $r->header('X-Xplogent-Timestamp')[0];

            return $r->url() === 'http://192.168.1.30:8765/voip/crm/dial' && $r['test'] === true
                && !isset($r['phone'])
                && $r->header('X-Xplogent-Signature-V2')[0] === Xplogent::sign($r->body(), self::TOKEN, $ts);
        });
    }

    public function test_failures_say_what_to_fix(): void
    {
        // One fake for all cases (a second Http::fake() would queue behind the first).
        $answer = null;
        Http::fake(['*' => function () use (&$answer) {
            return $answer ?? throw new ConnectionException('cURL error 7: Failed to connect to 192.168.1.30 port 8765');
        }]);
        $this->artisan('xplogent:check')
            ->expectsOutputToContain("Can't reach Xplogent at http://192.168.1.30:8765")
            ->expectsOutputToContain('xplogent-docker local --lan')
            ->assertExitCode(1);

        $answer = Http::response(['ok' => false, 'error' => 'timestamp skew too large: check both clocks'], 401);
        $this->artisan('xplogent:check')->expectsOutputToContain('NTP')->assertExitCode(1);

        $answer = Http::response(['ok' => false, 'error' => 'the CRM link is switched off'], 503);
        $this->artisan('xplogent:check')->expectsOutputToContain('switch Send on')->assertExitCode(1);

        config(['xplogent.base_url' => '']);
        $this->artisan('xplogent:check')->expectsOutputToContain('XPLOGENT_BASE_URL or XPLOGENT_API_TOKEN is not set')
            ->assertExitCode(1);
    }

    public function test_the_page_button_needs_the_permission_and_a_failed_call_explains_itself(): void
    {
        $up = true;
        Http::fake(['*' => function () use (&$up) {
            return $up ? Http::response(['ok' => true, 'test' => true, 'agent_enabled' => true])
                : throw new ConnectionException('Connection refused');
        }]);
        $this->actingAs($this->staff('Nobody'))->postJson('/xplogent/agent-call/check')->assertForbidden();
        $staff = $this->staff('Jane Recruiter', 'xplogent-agent-call');
        $this->actingAs($staff)->postJson('/xplogent/agent-call/check')->assertOk()
            ->assertJson(['ok' => true, 'url' => 'http://192.168.1.30:8765', 'warning' => '']);

        $up = false;
        $sale = $this->sale();
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $r = $this->actingAs($staff)->postJson('/xplogent/agent-call/dial', ['applicant_id' => $a->id,
            'sale_id' => $sale->id])->assertStatus(502);
        $this->assertStringContainsString("Can't reach Xplogent", $r->json('error'));
        $this->assertStringContainsString('xplogent-docker local --lan', $r->json('error'));
    }
}
