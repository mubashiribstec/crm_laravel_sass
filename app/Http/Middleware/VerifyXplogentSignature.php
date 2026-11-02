<?php

namespace App\Http\Middleware;

use App\Support\Xplogent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from Xplogent (the AI phone agent).
 *
 *   Authorization: Bearer <XPLOGENT_API_TOKEN>
 *   X-Xplogent-Timestamp: <unix seconds>
 *   X-Xplogent-Signature-V2: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
 *
 * The same scheme signs requests in the other direction (CRM -> Xplogent), see
 * App\Support\Xplogent::signedHeaders().
 */
class VerifyXplogentSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $token = (string) config('xplogent.api_token');

        if ($token === '') {
            return $this->log($request, Xplogent::error(503, 'not_configured',
                'XPLOGENT_API_TOKEN is not set on the CRM'), $started);
        }
        $body = (string) $request->getContent();
        if (strlen($body) > (int) config('xplogent.max_body', 262144)) {
            return $this->log($request, Xplogent::error(413, 'too_large', 'request body is larger than 256 KB'), $started);
        }
        $auth = (string) $request->header('Authorization', '');
        if (!hash_equals('Bearer ' . $token, $auth)) {
            return $this->log($request, Xplogent::error(401, 'unauthorized', 'bad token'), $started);
        }
        $ts = (string) $request->header('X-Xplogent-Timestamp', '');
        if ($ts === '' || !ctype_digit($ts)) {
            return $this->log($request, Xplogent::error(401, 'unauthorized', 'timestamp missing'), $started);
        }
        if (abs(time() - (int) $ts) > (int) config('xplogent.max_skew', 300)) {
            return $this->log($request, Xplogent::error(401, 'stale', 'timestamp skew too large: check both clocks'), $started);
        }
        $want = Xplogent::sign($body, $token, $ts);
        if (!hash_equals($want, (string) $request->header('X-Xplogent-Signature-V2', ''))) {
            return $this->log($request, Xplogent::error(401, 'unauthorized', 'bad signature'), $started);
        }
        // The CRM's observers audit every change as the signed-in user (or user 1
        // when there is none), so for this request "Xplogent AI" is that user. In
        // memory only: no session, no login, no remember cookie.
        if ($ai = Xplogent::aiUser()) {
            Auth::setUser($ai);
        }

        return $this->log($request, $next($request), $started);
    }

    /** One line per request, without personal data (no names, numbers or notes). */
    private function log(Request $request, Response $response, float $started): Response
    {
        Log::info('xplogent api', [
            'endpoint' => '/' . ltrim($request->path(), '/'),
            'applicant_id' => $request->json('applicant_id'),
            'sale_id' => $request->json('sale_id'),
            'status' => $response->getStatusCode(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $response;
    }
}
