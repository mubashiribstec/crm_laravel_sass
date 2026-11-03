<?php

namespace App\Http\Controllers;

use App\Support\DialLink;
use App\Support\PhoneNumber;
use Horsefly\Applicant;
use Horsefly\DialCallLog;
use Horsefly\DialLock;
use Horsefly\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

/**
 * Prevents the same number from being dialled again while it is "in use".
 *
 * Two independent lock timers are supported (both configurable in Settings):
 *   same_user_minutes   — how long the dialling agent themselves is re-locked
 *                         (0 = same agent can re-dial immediately)
 *   other_user_minutes  — how long ALL other agents are blocked (default 5 min)
 *
 * On top of that, a per-agent daily call cap is enforced:
 *   max_calls_per_day   — how many times one agent may call the same number
 *                         per day (0 = unlimited), tracked in dial_call_logs
 *   history_days        — how many days of per-agent call history to keep
 *                         before older rows are purged
 *
 * The master "dialing_lock_enabled" toggle bypasses all locking (including
 * the daily cap) when off.
 */
class DialLockController extends Controller
{
    public function __construct()
    {
        // Agents take and release their own locks; seeing and clearing everyone's locks is
        // the Settings -> Dialing admin tool (it was open to every logged-in user).
        $this->middleware('permission:administrator-setting-index')->only(['activeList', 'clearLock', 'clearAllLocks']);
    }

    private function dialingSettings(): array
    {
        $rows = Setting::where('group', 'dialing')->pluck('value', 'key');
        return [
            'enabled'            => filter_var($rows->get('dialing_lock_enabled', 'true'), FILTER_VALIDATE_BOOLEAN),
            'same_user_minutes'  => max(0, (int) $rows->get('dialing_lock_same_user_minutes', 0)),
            'other_user_minutes' => max(1, (int) $rows->get('dialing_lock_other_user_minutes', 5)),
            'max_calls_per_day'  => max(0, (int) $rows->get('dialing_max_calls_per_day', 3)),
            'history_days'       => max(1, (int) $rows->get('dialing_history_days', 2)),
        ];
    }

    /** Per-agent daily call count / limit info for a phone_key, for the calling agent. */
    private function dailyCallInfo(?string $key, array $cfg): array
    {
        $limit = $cfg['max_calls_per_day'];
        $count = 0;

        if ($key && $limit > 0 && Auth::id()) {
            $count = (int) (DialCallLog::where('phone_key', $key)
                ->where('user_id', Auth::id())
                ->where('call_date', now()->toDateString())
                ->value('calls') ?? 0);
        }

        $reached = $cfg['enabled'] && $limit > 0 && $count >= $limit;

        return [
            'dailyCallCount'    => $count,
            'dailyCallLimit'    => $limit,
            'dailyLimitReached' => $reached,
            'dailyResetSeconds' => $reached ? (int) ceil(now()->diffInSeconds(now()->copy()->startOfDay()->addDay())) : 0,
        ];
    }

    /** GET /dialing/info?number=... — status for a number, personalised to the calling agent. */
    public function info(Request $request): JsonResponse
    {
        $request->validate([
            'token'  => ['nullable', 'string'],
            'number' => ['nullable', 'string', 'max:30'],
        ]);

        $number = $this->resolveDialNumber($request);
        $key = DialLock::keyFor($number);
        $cfg = $this->dialingSettings();
        $daily = $this->dailyCallInfo($key, $cfg);


        if (!$key) {
            return response()->json(array_merge(['callCount' => 0, 'locked' => false, 'lastCalledAgo' => null], $daily));
        }

        $row = DialLock::where('phone_key', $key)->first();
        if (!$row) {
            return response()->json(array_merge(['callCount' => 0, 'locked' => false, 'lastCalledAgo' => null], $daily));
        }

        $isSelf = $row->user_id && $row->user_id === Auth::id();
        $now    = now();

        $locked           = false;
        $remainingSeconds = 0;

        if ($cfg['enabled'] && $row->expires_at && $row->expires_at->isFuture()) {
            if ($isSelf) {
                if ($cfg['same_user_minutes'] > 0) {
                    $selfExpiry = $row->locked_at->copy()->addMinutes($cfg['same_user_minutes']);
                    if ($selfExpiry->isFuture()) {
                        $locked           = true;
                        $remainingSeconds = (int) ceil($now->diffInSeconds($selfExpiry));
                    }
                }
            } else {
                $locked           = true;
                $remainingSeconds = (int) ceil($now->diffInSeconds($row->expires_at));
            }
        }

        return response()->json(array_merge([
            'callCount'        => (int) $row->call_count,
            'lastCalledAgo'    => $row->locked_at ? $row->locked_at->diffForHumans() : null,
            'lastCalledBy'     => $row->user_name,
            'locked'           => $locked,
            'lockedBySelf'     => $isSelf && $locked,
            'lockedBy'         => $locked ? $row->user_name : null,
            'remainingSeconds' => $remainingSeconds,
        ], $daily));
    }

    /** POST /dialing/acquire — try to start a call; lock or allow depending on per-user timers. */
    public function acquire(Request $request): JsonResponse
    {
        $request->validate([
            'token'  => ['nullable', 'string'],
            'number' => ['nullable', 'string', 'max:30'],
        ]);

        $number = $this->resolveDialNumber($request);
        $key    = DialLock::keyFor($number);

        if (!$key || !$number) {
            return response()->json(['ok' => true, 'locked' => false, 'callCount' => 0]);
        }

        $cfg = $this->dialingSettings();

        if (!$cfg['enabled']) {
            return response()->json(['ok' => true, 'locked' => false, 'callCount' => 0]);
        }

        $user  = Auth::user();
        $now   = now();
        $today = $now->toDateString();
        $until = $now->copy()->addMinutes($cfg['other_user_minutes']);

        $applicantId = Applicant::whereNull('deleted_at')
            ->phoneMatches($number)
            ->value('id');

        return DB::transaction(function () use ($key, $number, $user, $now, $today, $until, $applicantId, $cfg) {
            // ── Per-agent daily call cap ─────────────────────────────────────
            if ($cfg['max_calls_per_day'] > 0) {
                $log = DialCallLog::where('phone_key', $key)
                    ->where('user_id', $user->id)
                    ->where('call_date', $today)
                    ->lockForUpdate()
                    ->first();

                if ($log && $log->calls >= $cfg['max_calls_per_day']) {
                    $resetAt   = $now->copy()->startOfDay()->addDay();
                    $remaining = (int) ceil($now->diffInSeconds($resetAt));

                    return response()->json([
                        'ok'                => false,
                        'reason'            => 'daily_limit',
                        'locked'            => false,
                        'dailyLimitReached' => true,
                        'dailyCallCount'    => (int) $log->calls,
                        'dailyCallLimit'    => $cfg['max_calls_per_day'],
                        'dialNumber'     => $number,
                        'remainingSeconds'  => $remaining,
                        'message'           => "Daily limit reached ({$log->calls}/{$cfg['max_calls_per_day']}) for this number. "
                            . "Resets in " . $this->humanSeconds($remaining) . ".",
                    ], 423);
                }
            }

            $row    = DialLock::where('phone_key', $key)->lockForUpdate()->first();
            $isSelf = $row && $row->user_id === $user->id;

            if ($row && $row->expires_at && $row->expires_at->isFuture()) {
                // ── Same agent ────────────────────────────────────────────────
                if ($isSelf) {
                    if ($cfg['same_user_minutes'] > 0) {
                        $selfExpiry = $row->locked_at->copy()->addMinutes($cfg['same_user_minutes']);
                        if ($selfExpiry->isFuture()) {
                            $remaining = (int) ceil($now->diffInSeconds($selfExpiry));
                            $ago       = $row->locked_at ? $row->locked_at->diffForHumans() : 'just now';
                            return response()->json([
                                'ok'               => false,
                                'reason'           => 'self_lock',
                                'locked'           => true,
                                'lockedBySelf'     => true,
                                'lockedBy'         => $user->name,
                                'callCount'        => (int) $row->call_count,
                                'remainingSeconds' => $remaining,
                                'lastCalledAgo'    => $ago,
                                'message'          => "You already called this number {$ago}. "
                                    . "Your re-dial lock expires in " . $this->humanSeconds($remaining) . ". "
                                    . "Called " . (int) $row->call_count . "× in total.",
                            ], 423);
                        }
                    }
                    // same_user_minutes = 0 OR self-lock expired → fall through to allow
                } else {
                    // ── Different agent ───────────────────────────────────────
                    $remaining = (int) ceil($now->diffInSeconds($row->expires_at));
                    $ago       = $row->locked_at ? $row->locked_at->diffForHumans() : 'just now';
                    $who       = $row->user_name ?: 'another agent';

                    return response()->json([
                        'ok'               => false,
                        'reason'           => 'other_lock',
                        'locked'           => true,
                        'lockedBySelf'     => false,
                        'lockedBy'         => $who,
                        'callCount'        => (int) $row->call_count,
                        'remainingSeconds' => $remaining,
                        'lastCalledAgo'    => $ago,
                        'message'          => "This number is being called by {$who} ({$ago}). "
                            . "Locked for another " . $this->humanSeconds($remaining) . ". "
                            . "Called " . (int) $row->call_count . "× in total.",
                    ], 423);
                }
            }

            $newCount = ($row ? (int) $row->call_count : 0) + 1;

            DialLock::updateOrCreate(
                ['phone_key' => $key],
                [
                    'full_number'  => $number,
                    'user_id'      => $user->id,
                    'user_name'    => $user->name,
                    'applicant_id' => $applicantId,
                    'call_count'   => $newCount,
                    'locked_at'    => $now,
                    'expires_at'   => $until,
                ]
            );

            // ── Record this call against the agent's daily count ─────────────
            $log = DialCallLog::firstOrNew([
                'phone_key' => $key,
                'user_id'   => $user->id,
                'call_date' => $today,
            ]);
            $log->calls = ($log->exists ? $log->calls : 0) + 1;
            $log->save();

            // ── Purge per-agent call history beyond the retention window ─────
            DialCallLog::where('call_date', '<', $now->copy()->subDays($cfg['history_days'])->toDateString())->delete();

            return response()->json([
                'ok'             => true,
                'locked'         => false,
                'callCount'      => $newCount,
                'dailyCallCount' => $log->calls,
                'dailyCallLimit' => $cfg['max_calls_per_day'],
            ]);
        });
    }

    /** POST /dialing/release — free a lock the calling agent holds. */
    public function release(Request $request): JsonResponse
    {
        $request->validate([
            'token'  => ['nullable', 'string'],
            'number' => ['nullable', 'string', 'max:30'],
        ]);

        $key = DialLock::keyFor($this->resolveDialNumber($request));
        if ($key) {
            DialLock::where('phone_key', $key)
                ->where('user_id', Auth::id())
                ->update(['expires_at' => now()->subSecond()]);
        }

        return response()->json(['ok' => true]);
    }

    /** GET /dialing/active-locks — all currently active locks for the admin settings panel. */
    public function activeList(): JsonResponse
    {
        $reveal = $this->canViewPhoneNumber();
        $locks = DialLock::where('expires_at', '>', now())
            ->orderBy('expires_at', 'asc')
            ->get()
            ->map(fn($r) => [
                'id'               => $r->id,
                'full_number'      => $reveal ? $r->full_number : PhoneNumber::mask($r->full_number),
                'user_name'        => $r->user_name ?: 'Unknown',
                'locked_at'        => $r->locked_at?->format('H:i:s'),
                'expires_at_iso'   => $r->expires_at?->toIso8601String(),
                'remaining_seconds' => (int) ceil(now()->diffInSeconds($r->expires_at)),
                'call_count'       => (int) $r->call_count,
            ]);

        $callsToday = DialLock::whereDate('locked_at', today())->count();

        return response()->json([
            'locks'       => $locks,
            'stats'       => [
                'active_count' => $locks->count(),
                'calls_today'  => $callsToday,
            ],
        ]);
    }

    /** POST /dialing/clear-lock — admin: expire a specific lock immediately. */
    public function clearLock(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);
        DialLock::where('id', $request->id)->update(['expires_at' => now()->subSecond()]);
        return response()->json(['ok' => true]);
    }

    /** POST /dialing/clear-all-locks — admin: expire every active lock. */
    public function clearAllLocks(): JsonResponse
    {
        $count = DialLock::where('expires_at', '>', now())->count();
        DialLock::where('expires_at', '>', now())->update(['expires_at' => now()->subSecond()]);
        return response()->json(['ok' => true, 'cleared' => $count]);
    }

    private function humanSeconds(int $s): string
    {
        if ($s >= 3600) {
            $h = intdiv($s, 3600);
            $m = intdiv($s % 3600, 60);
            return $m ? "{$h}h {$m}m" : "{$h}h";
        }
        if ($s >= 60) {
            $m = intdiv($s, 60);
            $r = $s % 60;
            return $r ? "{$m}m {$r}s" : "{$m}m";
        }
        return "{$s}s";
    }

    private function resolveDialNumber(Request $request): ?string
    {
        if ($request->filled('token')) {
            return DialLink::resolve($request->input('token'));
        }

        $number = trim((string) $request->input('number', ''));
        return $number !== '' ? $number : null;
    }

    private function canViewPhoneNumber(): bool
    {
        return Gate::allows('applicant-view-phone-number');
    }
}
