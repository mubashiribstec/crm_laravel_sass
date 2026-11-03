<?php

namespace Tests\Feature\Xplogent;

class XplogentSignatureTest extends XplogentTestCase
{
    public function test_a_correctly_signed_request_passes(): void
    {
        $this->signed('/api/xplogent/sales/open', [])->assertOk()->assertJson(['api_version' => 2]);
    }

    public function test_wrong_token_signature_or_stale_timestamp_get_401(): void
    {
        $this->signed('/api/xplogent/sales/open', [], 'wrong-token-0123456789abcdef0123456789')->assertStatus(401);
        $this->signed('/api/xplogent/sales/open', [], null, time() - 3600)
            ->assertStatus(401)->assertJson(['error' => 'stale']);
        $body = json_encode([]);
        $this->call('POST', '/api/xplogent/sales/open', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            'HTTP_X_XPLOGENT_TIMESTAMP' => (string) time(), 'HTTP_X_XPLOGENT_SIGNATURE_V2' => 'sha256=00',
        ], $body)->assertStatus(401)->assertJson(['ok' => false, 'error' => 'unauthorized']);
        $this->postJson('/api/xplogent/sales/open', [])->assertStatus(401);       // nothing signed at all
    }

    public function test_no_token_configured_gets_503_and_oversized_body_413(): void
    {
        $big = ['search' => str_repeat('x', 300 * 1024)];
        $this->signed('/api/xplogent/sales/open', $big)->assertStatus(413);
        config(['xplogent.api_token' => '']);
        $this->signed('/api/xplogent/sales/open', [])->assertStatus(503)->assertJson(['error' => 'not_configured']);
    }

    public function test_validation_errors_use_the_xplogent_shape(): void
    {
        $this->signed('/api/xplogent/campaigns/queue', [])->assertStatus(422)
            ->assertJson(['api_version' => 2, 'ok' => false, 'error' => 'invalid']);
    }
}
