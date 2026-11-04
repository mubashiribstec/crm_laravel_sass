<?php

namespace App\Support;

use Horsefly\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Small helpers shared by the Xplogent API, its services and the Agent call page.
 */
class Xplogent
{
    public const API_VERSION = 2;
    public const AI_USER_NAME = 'Xplogent AI';

    /** "sha256=<hex HMAC-SHA256 of '<timestamp>.<body>'>" (Xplogent's V2 signature). */
    public static function sign(string $body, string $token, string $timestamp): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $token);
    }

    /** Headers for a request the CRM sends to Xplogent (same scheme, same token). */
    public static function signedHeaders(string $body, ?string $token = null): array
    {
        $token ??= (string) config('xplogent.api_token');
        $ts = (string) time();

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Xplogent-Timestamp' => $ts,
            'X-Xplogent-Signature-V2' => self::sign($body, $token, $ts),
            'Content-Type' => 'application/json',
        ];
    }

    /** A reply in the documented shape: always `ok: true` unless $data says `ok: false` itself. */
    public static function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(array_merge(['api_version' => self::API_VERSION, 'ok' => true], $data), $status);
    }

    public static function error(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['api_version' => self::API_VERSION, 'ok' => false,
            'error' => $code, 'message' => $message] + $extra, $status);
    }

    /** The "Xplogent AI" user every API write is attributed to (null if not set up). */
    public static function aiUser(): ?User
    {
        $id = config('xplogent.ai_user_id');

        return $id ? User::find($id) : null;
    }

    /**
     * A number Xplogent can dial, in the form the dial expects.
     *
     * UK numbers are normalised to E.164: 07… -> +447…, 01/02/03… -> +44…;
     * numbers already in +44 / 0044 / 44 form are normalised the same way.
     *
     * Two more forms are passed through unchanged (they are already dialable):
     * - a number already in valid international E.164 (e.g. +92…, +1…) — the
     *   CRM is used for international recruitment, and Xplogent dials E.164
     *   numbers on its trunks; and
     * - a short PBX extension (2-6 chars of digits, *, # or + — e.g. "505"),
     *   which Xplogent dials on the PBX extension line (via sip/extension).
     *   A short digit string is never a valid UK national number (those are
     *   10-11 digits), so this cannot misclassify a UK number.
     *
     * Anything else (or too short) -> null.
     */
    public static function toE164(?string $number): ?string
    {
        $clean = preg_replace('/[\s\-\(\)]/', '', trim((string) $number));
        if ($clean === '') {
            return null;
        }

        // Already valid international E.164 (e.g. +92…, +1…, +44…) — pass through.
        if (preg_match('/^\+[1-9]\d{6,14}$/', $clean)) {
            return $clean;
        }

        // Short PBX extension (e.g. "505") — pass through for the extension line.
        if (preg_match('/^[0-9*#+]{2,6}$/', $clean)) {
            return $clean;
        }

        $digits = PhoneNumber::digits($number);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0044')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            $digits = '0' . substr($digits, 2);
        }
        if (preg_match('/^0([1237]\d{8,9})$/', $digits, $m)) {
            return '+44' . $m[1];
        }

        return null;
    }

    /** E.164 (or anything) back to the national form the CRM stores: +447… -> 07… */
    public static function toNational(?string $number): string
    {
        $digits = PhoneNumber::digits($number);
        if (str_starts_with($digits, '0044')) {
            return '0' . substr($digits, 4);
        }
        if (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            return '0' . substr($digits, 2);
        }

        return $digits;
    }

    /** The outward part of a UK postcode ("LS6 2AB" -> "LS6"). */
    public static function district(?string $postcode): string
    {
        $pc = strtoupper(trim((string) $postcode));
        if ($pc === '') {
            return '';
        }
        if (str_contains($pc, ' ')) {
            return explode(' ', $pc)[0];
        }

        return strlen($pc) > 3 ? substr($pc, 0, -3) : $pc;
    }

    public static function firstName(?string $name): string
    {
        return ucfirst(strtolower(Str::before(trim((string) $name), ' ')));
    }

    /** Plain text, single-spaced, capped. CRM text is untrusted data for the AI. */
    public static function clip(?string $text, int $max): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $text))));

        return mb_strlen($plain) > $max ? rtrim(mb_substr($plain, 0, $max - 1)) . '…' : $plain;
    }

    /** "<details> --- By: <name> Date: d-m-Y", the CRM's note format. */
    public static function noteSuffix(string $details, string $by): string
    {
        return $details . ' --- By: ' . $by . ' Date: ' . now()->format('d-m-Y');
    }
}
