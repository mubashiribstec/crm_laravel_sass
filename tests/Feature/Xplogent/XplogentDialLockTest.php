<?php

namespace Tests\Feature\Xplogent;

use Horsefly\DialLock;
use Horsefly\Setting;
use Horsefly\User;

class XplogentDialLockTest extends XplogentTestCase
{
    private function lock(string $token, string $phone = '+447700900123', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->signed('/api/xplogent/dial-lock', array_merge(['phone' => $phone, 'ttl_seconds' => 900,
            'lock_token' => $token], $extra));
    }

    public function test_only_the_same_token_renews_or_releases(): void
    {
        $this->lock('token-aaaaaaaa')->assertOk()->assertJson(['locked' => true, 'renewed' => false]);
        $row = DialLock::first();
        $this->assertSame('Xplogent AI', $row->user_name);
        $this->assertSame('07700900123', $row->full_number);
        $this->lock('token-aaaaaaaa')->assertOk()->assertJson(['renewed' => true]);
        $this->assertSame(1, (int) DialLock::first()->call_count);                       // a renewal is not a new call
        $this->lock('token-bbbbbbbb')->assertStatus(409)->assertJson(['locked' => false, 'locked_by' => 'Xplogent AI']);
        $this->signed('/api/xplogent/dial-unlock', ['phone' => '07700 900123', 'lock_token' => 'token-bbbbbbbb'])
            ->assertStatus(409)->assertJson(['ok' => false, 'error' => 'not_your_lock', 'released' => false,
                'locked_by' => 'Xplogent AI']);
        $this->signed('/api/xplogent/dial-unlock', ['phone' => '07700 900123', 'lock_token' => 'token-aaaaaaaa'])
            ->assertOk()->assertJson(['ok' => true, 'released' => true]);
        $this->signed('/api/xplogent/dial-unlock', ['phone' => '07700 900123', 'lock_token' => 'token-aaaaaaaa'])
            ->assertOk()->assertJson(['ok' => true, 'released' => false]);           // twice is harmless
        $this->lock('token-bbbbbbbb')->assertOk();                                       // free again
    }

    /** X-23: an unlock that can't do anything says so. */
    public function test_unlock_needs_a_token_and_the_ai_user(): void
    {
        $this->signed('/api/xplogent/dial-unlock', ['phone' => '07700 900123'])->assertStatus(422);
        config(['xplogent.ai_user_id' => null]);
        $this->signed('/api/xplogent/dial-unlock', ['phone' => '07700 900123', 'lock_token' => 'token-aaaaaaaa'])
            ->assertStatus(503)->assertJson(['error' => 'ai_user_missing']);
    }

    /** X-22: the TTL asked for is the TTL applied, up to two hours; more is refused, not cut short. */
    public function test_the_lock_ttl_is_applied_and_reported(): void
    {
        $this->lock('token-aaaaaaaa', '+447700900123', ['ttl_seconds' => 2100])->assertOk()
            ->assertJson(['ttl_seconds' => 2100]);
        $this->assertEqualsWithDelta(2100, now()->diffInSeconds(DialLock::first()->expires_at), 5);
        $this->lock('token-aaaaaaaa', '+447700900456', ['ttl_seconds' => 7200])->assertOk()->assertJson(['ttl_seconds' => 7200]);
        $this->lock('token-aaaaaaaa', '+447700900789', ['ttl_seconds' => 7201])->assertStatus(422);
        $this->lock('token-aaaaaaaa', '+447700900789', ['ttl_seconds' => 10])->assertOk()->assertJson(['ttl_seconds' => 60]);
    }

    public function test_a_lock_token_is_required(): void
    {
        $this->signed('/api/xplogent/dial-lock', ['phone' => '+447700900123'])->assertStatus(422);
        $this->lock('short')->assertStatus(422);
    }

    public function test_a_recruiters_lock_blocks_the_ai_and_the_ai_blocks_the_recruiter(): void
    {
        $recruiter = User::factory()->create(['name' => 'Jane Recruiter']);
        $this->actingAs($recruiter)->postJson('/dialing/acquire', ['number' => '07700900123'])->assertOk();
        $this->lock('token-aaaaaaaa')->assertStatus(409)->assertJson(['locked_by' => 'Jane Recruiter']);

        $this->lock('token-cccccccc', '+447700900456')->assertOk();
        $this->actingAs($recruiter)->postJson('/dialing/acquire', ['number' => '07700 900456'])
            ->assertStatus(423)->assertJson(['reason' => 'other_lock', 'lockedBy' => 'Xplogent AI']);
    }

    public function test_locking_switched_off_in_the_crm_is_respected(): void
    {
        Setting::create(['group' => 'dialing', 'key' => 'dialing_lock_enabled', 'value' => 'false']);
        // X-24: the reply means "go ahead": the CRM's dial lock is off for everyone, recruiters too,
        // so nothing is stored. Xplogent dials only on locked: true, so this shape is kept.
        $this->lock('token-aaaaaaaa')->assertOk()
            ->assertJson(['ok' => true, 'locked' => true, 'locking' => 'off', 'expires_at' => null]);
        $this->assertSame(0, DialLock::count());
    }
}
