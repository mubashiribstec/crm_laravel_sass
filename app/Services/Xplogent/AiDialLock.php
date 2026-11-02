<?php

namespace App\Services\Xplogent;

use App\Support\Xplogent;
use Horsefly\Applicant;
use Horsefly\DialCallLog;
use Horsefly\DialLock;
use Horsefly\Setting;
use Horsefly\User;
use Illuminate\Support\Facades\DB;

/**
 * The CRM dial lock, taken by Xplogent's calls (DialLockController::acquire is
 * the recruiters' side). The AI's lock is an ordinary dial_locks row owned by
 * the "Xplogent AI" user, so a recruiter's click-to-dial shows "being called
 * by Xplogent AI", and a recruiter's lock blocks the AI. Each AI lock carries a
 * per-call lock_token: only that token renews or releases it.
 */
class AiDialLock
{
    /** @return array{status: int, body: array} */
    public function lock(string $phone, ?int $applicantId, int $ttlSeconds, string $token, User $ai): array
    {
        $key = DialLock::keyFor($phone);
        if ($key === null) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'bad_phone', 'message' => 'not a phone number']];
        }
        if (!$this->lockingEnabled()) {
            return ['status' => 200, 'body' => ['ok' => true, 'locked' => true, 'expires_at' => null, 'locking' => 'off']];
        }
        $ttlSeconds = max(60, min(3600, $ttlSeconds));
        $applicantId ??= Applicant::whereNull('deleted_at')->phoneMatches($phone)->value('id');

        return DB::transaction(function () use ($key, $phone, $applicantId, $ttlSeconds, $token, $ai) {
            $now = now();
            $row = DialLock::where('phone_key', $key)->lockForUpdate()->first();
            if ($row && $row->expires_at && $row->expires_at->isFuture()) {
                $theirs = (int) $row->user_id !== (int) $ai->id || !hash_equals((string) $row->lock_token, $token);
                if ($theirs) {
                    return ['status' => 409, 'body' => ['ok' => false, 'locked' => false,
                        'locked_by' => $row->user_name ?: 'another agent',
                        'expires_at' => $row->expires_at->toIso8601ZuluString()]];
                }
            }
            $sameLease = $row && (int) $row->user_id === (int) $ai->id && hash_equals((string) $row->lock_token, $token)
                && $row->expires_at && $row->expires_at->isFuture();
            $until = $now->copy()->addSeconds($ttlSeconds);

            DialLock::updateOrCreate(['phone_key' => $key], [
                'full_number' => Xplogent::toNational($phone),
                'user_id' => $ai->id,
                'user_name' => $ai->name,
                'applicant_id' => $applicantId,
                'lock_token' => $token,
                'call_count' => ($row ? (int) $row->call_count : 0) + ($sameLease ? 0 : 1),
                'locked_at' => $sameLease ? $row->locked_at : $now,
                'expires_at' => $until,
            ]);
            if (!$sameLease) {                       // a new call: the per-number daily history
                $log = DialCallLog::firstOrNew(['phone_key' => $key, 'user_id' => $ai->id,
                    'call_date' => $now->toDateString()]);
                $log->calls = ($log->exists ? $log->calls : 0) + 1;
                $log->save();
            }

            return ['status' => 200, 'body' => ['ok' => true, 'locked' => true,
                'expires_at' => $until->toIso8601ZuluString(), 'renewed' => $sameLease]];
        });
    }

    /** Release the AI's own lock for this number, only with the same token. */
    public function unlock(string $phone, string $token, User $ai): bool
    {
        $key = DialLock::keyFor($phone);
        if ($key === null || $token === '') {
            return false;
        }

        return DialLock::where('phone_key', $key)->where('user_id', $ai->id)->where('lock_token', $token)
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now(), 'lock_token' => null]) > 0;
    }

    /** Whether someone else holds an unexpired lock on this number (for the Agent call page). */
    public function heldBy(string $phone): ?string
    {
        $key = DialLock::keyFor($phone);
        $row = $key ? DialLock::where('phone_key', $key)->where('expires_at', '>', now())->first() : null;

        return $row ? ($row->user_name ?: 'another agent') : null;
    }

    private function lockingEnabled(): bool
    {
        $value = Setting::where('group', 'dialing')->where('key', 'dialing_lock_enabled')->value('value');

        return filter_var($value ?? 'true', FILTER_VALIDATE_BOOLEAN);
    }
}
