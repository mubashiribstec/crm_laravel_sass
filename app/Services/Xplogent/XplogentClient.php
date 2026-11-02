<?php

namespace App\Services\Xplogent;

use App\Support\Xplogent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Requests the CRM sends to Xplogent (POST {XPLOGENT_BASE_URL}/voip/crm/dial), signed with
 * the shared token the same way Xplogent signs its own. The token never reaches a browser.
 */
class XplogentClient
{
    /** @return array{ok: bool, status: int, data: array, error: ?string} */
    public function post(string $path, array $payload, int $timeout = 20): array
    {
        $base = $this->baseUrl();
        if ($base === '' || (string) config('xplogent.api_token') === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'not_configured'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        try {
            $resp = Http::withHeaders(Xplogent::signedHeaders($body))->connectTimeout(5)->timeout($timeout)
                ->withoutRedirecting()->withBody($body, 'application/json')->post($base . $path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [],
                'error' => 'unreachable: ' . Str::limit($e->getMessage(), 160)];
        }
        $data = $resp->json();
        $data = is_array($data) ? $data : [];

        return ['ok' => $resp->successful() && ($data['ok'] ?? false) === true, 'status' => $resp->status(),
            'data' => $data, 'error' => isset($data['error']) ? (string) $data['error'] : null];
    }

    /**
     * Check the CRM -> Xplogent direction without dialling: a signed {"test": true} proves the
     * address, the firewall, the token and both clocks.
     *
     * @return array{ok: bool, url: string, reason: string, fix: string, warning: string, data: array}
     */
    public function check(string $by = 'CRM'): array
    {
        $r = $this->post('/voip/crm/dial', ['test' => true, 'requested_by' => $by], 10);
        if ($r['ok'] && ($r['data']['test'] ?? false) === true) {
            $warning = ($r['data']['agent_enabled'] ?? true) ? ''
                : "Xplogent's phone agent is switched off, so \"Call with AI\" will be refused until it is on "
                . '(Xplogent: Go live, switch on).';

            return ['ok' => true, 'url' => $this->baseUrl(), 'reason' => 'Xplogent answered and accepted the token.',
                'fix' => '', 'warning' => $warning, 'data' => $r['data']];
        }

        return ['ok' => false, 'url' => $this->baseUrl(), 'warning' => '', 'data' => $r['data']] + $this->explain($r);
    }

    /** What went wrong talking to Xplogent, and what to do, in plain words. */
    public function explain(array $r): array
    {
        $url = $this->baseUrl();
        $status = (int) ($r['status'] ?? 0);
        $error = (string) ($r['error'] ?? '');
        $network = ' On the Xplogent server, open its port to the LAN (sudo xplogent-docker local --lan) and '
            . 'allow port 8765 from this machine in its firewall. Xplogent shows the right address under '
            . 'Calls → Call agent → CRM.';

        return match (true) {
            $error === 'not_configured' => ['reason' => 'XPLOGENT_BASE_URL or XPLOGENT_API_TOKEN is not set.',
                'fix' => 'Set both in the CRM\'s .env (the address Xplogent shows under Calls → Call agent → CRM, '
                    . 'and the same token as on Xplogent), then run php artisan config:clear.'],
            $status === 0 => ['reason' => "Can't reach Xplogent at {$url} (" . Str::after($error, 'unreachable: ') . ').',
                'fix' => 'Check XPLOGENT_BASE_URL.' . $network],
            $status === 401 => ['reason' => 'Xplogent refused the request: ' . ($error ?: 'bad token or signature') . '.',
                'fix' => str_contains($error, 'clock') || str_contains($error, 'skew')
                    ? 'Turn on automatic time (NTP) on both servers.'
                    : 'Use exactly the same token here (XPLOGENT_API_TOKEN) and in Xplogent (Calls → Call agent → CRM).'],
            $status === 503 => ['reason' => "Xplogent's CRM link is switched off or has no token: {$error}.",
                'fix' => 'In Xplogent: Calls → Call agent → CRM, switch Send on and paste the token.'],
            $status === 404 => ['reason' => "There is no Xplogent CRM endpoint at {$url}.",
                'fix' => 'Check XPLOGENT_BASE_URL (no path after the port), and update Xplogent (sudo xplogent-docker update).'],
            $status === 400 => ['reason' => 'Xplogent answered but is too old for this check.',
                'fix' => 'Update Xplogent (sudo xplogent-docker update).'],
            $status === 429 => ['reason' => 'Xplogent is rate-limiting the CRM.', 'fix' => 'Wait a minute and try again.'],
            default => ['reason' => "Unexpected answer from Xplogent (HTTP {$status}" . ($error ? ": {$error}" : '') . ').',
                'fix' => 'Check Xplogent\'s logs (xplogent-docker logs).'],
        };
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('xplogent.base_url'), '/');
    }
}
