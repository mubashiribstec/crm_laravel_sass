<?php

namespace App\Services\Xplogent;

use App\Support\Xplogent;
use Carbon\Carbon;
use Horsefly\Applicant;
use Horsefly\CallLog;
use Horsefly\Sale;
use Horsefly\User;
use Horsefly\XplogentCallEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes an Xplogent call.completed summary into the CRM the way a recruiter
 * records a call: a call_logs row, the outcome through the CRM's own services
 * (ApplicantStatusService, SaleInterestService), and the payload in
 * xplogent_call_events. Idempotent on call_id.
 */
class CallSummaryWriter
{
    /** outcome => storeShortNotes reason ('' = note only, no flag change). */
    public const REASONS = [
        'callback' => 'callback',
        'recruiter_callback' => 'callback',
        'not_interested' => 'not_interested',
        'no_job' => 'no_job',
        'do_not_call' => 'blocked',
        'number_unobtainable' => 'circuit_busy',
        'interested' => 'casual',
    ];
    public const UNANSWERED = ['no_answer', 'voicemail', 'busy'];

    public function __construct(
        private ApplicantStatusService $status,
        private SaleInterestService $interest,
        private Directory $directory,
    ) {
    }

    /** @return array{duplicate: bool, call_log_id: ?int} */
    public function write(array $p, User $ai): array
    {
        $callId = (int) $p['call_id'];
        if (XplogentCallEvent::where('call_id', $callId)->exists()) {
            return ['duplicate' => true, 'call_log_id' => null];
        }

        $outcome = strtolower((string) ($p['outcome'] ?? 'other'));
        $direction = ($p['direction'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound';
        $candidatePhone = (string) ($direction === 'outbound' ? ($p['to'] ?? '') : ($p['from'] ?? ''));
        $applicant = !empty($p['applicant_id']) ? Applicant::find((int) $p['applicant_id']) : null;
        $applicant ??= $candidatePhone !== '' ? $this->directory->findByPhone($candidatePhone) : null;
        $sale = !empty($p['sale_id']) ? Sale::withTrashed()->find((int) $p['sale_id']) : null;

        try {
            return DB::transaction(function () use ($p, $ai, $callId, $outcome, $direction, $candidatePhone, $applicant, $sale) {
                $log = CallLog::create([
                    'user_id' => $ai->id,
                    'applicant_id' => $applicant?->id,
                    'sale_id' => $sale?->id,
                    'caller_number' => Xplogent::toNational($candidatePhone),
                    'caller_name' => $applicant?->applicant_name,
                    'direction' => $direction,
                    'duration_seconds' => max(0, (int) ($p['duration_s'] ?? 0)),
                    'sip_call_id' => mb_substr((string) ($p['provider_call_id'] ?? ''), 0, 255) ?: null,
                    'source' => 'xplogent',
                    'called_at' => $this->time($p['started_at'] ?? null) ?? now(),
                ]);
                XplogentCallEvent::create([
                    'call_id' => $callId,
                    'applicant_id' => $applicant?->id,
                    'sale_id' => $sale?->id,
                    'call_log_id' => $log->id,
                    'outcome' => mb_substr($outcome, 0, 40),
                    'payload' => $p,
                ]);
                if ($applicant) {
                    $this->recordOutcome($applicant, $sale, $outcome, $p, $ai);
                }

                return ['duplicate' => false, 'call_log_id' => $log->id];
            });
        } catch (QueryException $e) {
            // Two deliveries of the same call racing: the unique call_id lets one win.
            if (XplogentCallEvent::where('call_id', $callId)->exists()) {
                return ['duplicate' => true, 'call_log_id' => null];
            }
            throw $e;
        }
    }

    private function recordOutcome(Applicant $applicant, ?Sale $sale, string $outcome, array $p, User $ai): void
    {
        if (in_array($outcome, self::UNANSWERED, true)) {
            if (!empty($p['final_attempt'])) {      // the last try: the recruiter's "no response"
                $note = "AI call ({$outcome}): no answer on the final attempt" . ($sale ? " for sale #{$sale->id}" : '') . '.';
                $this->status->apply($applicant->id, 'no_response', $note, $ai);
            }
            return;                                 // otherwise: the call log is the record
        }

        $note = $this->noteText($outcome, $p, $applicant);
        if ($outcome === 'interested' && $sale) {
            $this->interest->revertNotInterested($applicant->id, $sale->id, $ai);
        }
        if ($outcome === 'not_interested' && $sale) {
            // Not interested in THIS vacancy: the CRM's per-sale record, not the global flag.
            $this->interest->markNotInterested($applicant->id, $sale->id, $note, $ai);
            return;
        }
        $this->status->apply($applicant->id, self::REASONS[$outcome] ?? '', $note, $ai);
    }

    /** "AI call (callback): <summary>. Callback Wed 29 Oct 17:30. Answers: …" (≤ 2,000 chars). */
    public function noteText(string $outcome, array $p, Applicant $applicant): string
    {
        $parts = [];
        $summary = Xplogent::clip($p['summary'] ?? '', 1200);
        $parts[] = ($outcome === 'recruiter_callback' ? 'RECRUITER CALLBACK: ' : '')
            . "AI call ({$outcome})" . ($summary !== '' ? ': ' . rtrim($summary, '.') : '') . '.';

        if (in_array($outcome, ['callback', 'recruiter_callback'], true) && ($at = $this->time($p['callback_at'] ?? null))) {
            $parts[] = 'Callback ' . $at->setTimezone(config('xplogent.timezone', 'Europe/London'))->format('D j M H:i') . '.';
        }
        $answers = [];
        foreach ((array) ($p['answers'] ?? []) as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $answers[] = ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . Xplogent::clip((string) $value, 150);
            }
        }
        if ($answers) {
            $parts[] = 'Answers: ' . implode('; ', $answers) . '.';
        }
        $email = strtolower(trim((string) ($p['email_confirmed'] ?? '')));
        $known = array_map('strtolower', array_filter([$applicant->applicant_email, $applicant->applicant_email_secondary]));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $known, true)) {
            $parts[] = "Candidate gave email {$email}.";
        }

        // The transcript is never written into notes: it stays in xplogent_call_events.
        return Xplogent::clip(implode(' ', $parts), 2000);
    }

    private function time($value): ?Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
