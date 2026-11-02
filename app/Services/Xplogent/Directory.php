<?php

namespace App\Services\Xplogent;

use App\Support\PhoneNumber;
use App\Support\Xplogent;
use Carbon\Carbon;
use Horsefly\Applicant;
use Horsefly\ApplicantNote;
use Horsefly\ApplicantPivotSale;
use Horsefly\CallLog;
use Horsefly\CVNote;
use Horsefly\DialLock;
use Horsefly\History;
use Horsefly\Interview;
use Horsefly\ModuleNote;
use Horsefly\Sale;
use Illuminate\Support\Collection;

/**
 * What Xplogent may know about vacancies and candidates: open sales, a sale's
 * brief, the call queue for a sale, and the contact card for a caller.
 * Data-minimised: never the CV file, date of birth, gender, emails or the full
 * postcode (an opt-in, redacted CV excerpt is the one exception).
 */
class Directory
{
    public const SKIP_REASONS = ['blocked', 'not_interested', 'no_job', 'closed', 'already_sent',
        'locked', 'recently_called', 'no_phone'];

    public function __construct(private CandidateQueueService $queue, private CvExcerpt $cv)
    {
    }

    // ── sales ─────────────────────────────────────────────────────────────────
    public function openSales(string $search = '', int $limit = 50): array
    {
        $query = Sale::query()
            ->with(['jobTitle:id,name', 'jobCategory:id,name', 'office:id,office_name', 'unit:id,unit_name'])
            ->where('status', 1)->where('is_on_hold', 0)
            ->where(fn ($q) => $q->where('is_blacklist', false)->orWhereNull('is_blacklist'))
            ->orderByDesc('id');

        foreach (array_filter(preg_split('/\s+/', trim($search))) as $word) {
            $like = '%' . $word . '%';
            $query->where(function ($q) use ($like) {
                $q->where('sale_postcode', 'like', $like)
                    ->orWhereHas('jobTitle', fn ($t) => $t->where('name', 'like', $like))
                    ->orWhereHas('jobCategory', fn ($t) => $t->where('name', 'like', $like))
                    ->orWhereHas('office', fn ($t) => $t->where('office_name', 'like', $like))
                    ->orWhereHas('unit', fn ($t) => $t->where('unit_name', 'like', $like));
            });
        }

        $sales = $query->limit($limit)->get();
        $cvs = CVNote::whereIn('sale_id', $sales->pluck('id'))->where('status', 1)
            ->selectRaw('sale_id, COUNT(*) AS n')->groupBy('sale_id')->pluck('n', 'sale_id');

        return $sales->map(fn (Sale $s) => [
            'sale_id' => $s->id,
            'job_title' => (string) optional($s->jobTitle)->name,
            'job_category' => (string) optional($s->jobCategory)->name,
            'postcode' => (string) $s->sale_postcode,
            'postcode_district' => Xplogent::district($s->sale_postcode),
            'office_name' => (string) optional($s->office)->office_name,
            'unit_name' => (string) optional($s->unit)->unit_name,
            'position_type' => (string) $s->position_type,
            'salary' => Xplogent::clip($s->salary, 200),
            'cvs_sent' => (int) ($cvs[$s->id] ?? 0),
            'cv_limit' => (int) $s->cv_limit,
            'created_at' => optional($s->created_at)->toIso8601ZuluString(),
        ])->values()->all();
    }

    public function cvsSent(Sale $sale): int
    {
        return CVNote::where('sale_id', $sale->id)->where('status', 1)->count();
    }

    /** Why this sale can't be called ("" = callable). */
    public function notCallable(?Sale $sale): string
    {
        if (!$sale || $sale->trashed()) {
            return 'the vacancy does not exist';
        }
        if ((int) $sale->status !== 1) {
            return 'the vacancy is closed';
        }
        if ((int) $sale->is_on_hold === 1) {
            return 'the vacancy is on hold';
        }
        if ($sale->is_blacklist) {
            return 'the vacancy is blacklisted';
        }
        if ($sale->lat === null || $sale->lng === null) {
            return 'the vacancy has no location coordinates';
        }
        if ((int) $sale->cv_limit > 0 && $this->cvsSent($sale) >= (int) $sale->cv_limit) {
            return 'the vacancy has reached its CV limit';
        }

        return '';
    }

    /** The vacancy as the AI may talk about it (ROLE FACTS). */
    public function saleBrief(Sale $sale): array
    {
        $sale->loadMissing(['jobTitle:id,name', 'jobCategory:id,name', 'office:id,office_name', 'unit:id,unit_name']);
        $share = (bool) config('xplogent.share_client_name');

        return [
            'sale_id' => $sale->id,
            'job_title' => (string) optional($sale->jobTitle)->name,
            'job_category' => (string) optional($sale->jobCategory)->name,
            'position_type' => (string) $sale->position_type,
            'job_type' => (string) $sale->job_type,
            'timing' => Xplogent::clip($sale->timing, 300),
            'salary' => Xplogent::clip($sale->salary, 200),
            'experience' => Xplogent::clip($sale->experience, 300),
            'qualification' => Xplogent::clip($sale->qualification, 300),
            'benefits' => Xplogent::clip($sale->benefits, 300),
            'description' => Xplogent::clip($sale->job_description, 800),
            'postcode_district' => Xplogent::district($sale->sale_postcode),
            'client_name' => $share ? optional($sale->office)->office_name : null,
            'site_name' => $share ? optional($sale->unit)->unit_name : null,
            'cvs_sent' => $this->cvsSent($sale),
            'cv_limit' => (int) $sale->cv_limit,
        ];
    }

    // ── the call queue for a sale ─────────────────────────────────────────────
    /** @return array{candidates: array, skipped: array} */
    public function queue(Sale $sale, float $radiusKm, int $limit, int $excludeCalledWithinDays, ?int $aiUserId): array
    {
        $columns = ['applicants.id', 'applicants.applicant_name', 'applicants.applicant_phone',
            'applicants.applicant_phone_secondary', 'applicants.applicant_landline', 'applicants.applicant_postcode',
            'applicants.job_title_id', 'applicants.is_blocked', 'applicants.is_temp_not_interested',
            'applicants.is_no_job', 'applicants.is_callback_enable', 'applicants.is_no_response',
            'applicants.is_circuit_busy', 'applicants.paid_status', 'applicants.updated_at'];
        $rows = $this->queue->withDistance($this->queue->radiusQuery($sale, $radiusKm, [], $columns), $sale)->get();

        $ids = $rows->pluck('id')->all();
        $cvSent = CVNote::where('sale_id', $sale->id)->whereIn('applicant_id', $ids)->pluck('applicant_id')->flip();
        $notHere = ApplicantPivotSale::where('sale_id', $sale->id)->whereIn('applicant_id', $ids)->pluck('applicant_id')->flip();
        $locked = DialLock::where('expires_at', '>', now())->pluck('phone_key')->flip();
        $recent = $aiUserId ? CallLog::where('user_id', $aiUserId)->where('sale_id', $sale->id)
            ->whereIn('applicant_id', $ids)->where('called_at', '>=', now()->subDays($excludeCalledWithinDays))
            ->pluck('applicant_id')->flip() : collect();
        $lastCalled = CallLog::whereIn('applicant_id', $ids)->selectRaw('applicant_id, MAX(called_at) AS at')
            ->groupBy('applicant_id')->pluck('at', 'applicant_id');

        $skipped = array_fill_keys(self::SKIP_REASONS, 0);
        $out = [];
        foreach ($rows as $a) {
            $reason = match (true) {
                (bool) $a->is_blocked => 'blocked',
                (bool) $a->is_temp_not_interested, isset($notHere[$a->id]) => 'not_interested',
                (bool) $a->is_no_job => 'no_job',
                $a->paid_status === 'close' => 'closed',
                isset($cvSent[$a->id]) => 'already_sent',
                $this->anyLocked($a, $locked) => 'locked',
                isset($recent[$a->id]) => 'recently_called',
                default => '',
            };
            $phone = $reason === '' ? $this->bestPhone($a) : null;
            if ($reason === '' && $phone === null) {
                $reason = 'no_phone';
            }
            if ($reason !== '') {
                $skipped[$reason]++;
                continue;
            }
            $km = round((float) $a->distance_km, 1);
            $out[] = [
                'applicant_id' => $a->id,
                'name' => (string) $a->applicant_name,
                'first_name' => Xplogent::firstName($a->applicant_name),
                'phone_e164' => $phone['e164'],
                'phone_kind' => $phone['kind'],
                'postcode_district' => Xplogent::district($a->applicant_postcode),
                'distance_km' => $km,
                'distance_miles' => round($km * 0.621371, 1),
                'job_title' => (string) $a->job_title_name,
                'flags' => ['callback' => (bool) $a->is_callback_enable, 'no_response' => (bool) $a->is_no_response,
                    'circuit_busy' => (bool) $a->is_circuit_busy],
                'last_note' => Xplogent::clip($a->notes_details, 300),
                'last_note_at' => $a->notes_created_at ? Carbon::parse($a->notes_created_at)->toIso8601ZuluString() : null,
                'last_called_at' => isset($lastCalled[$a->id]) ? Carbon::parse($lastCalled[$a->id])->toIso8601ZuluString() : null,
            ];
        }

        // Nearest first; at the same distance, never-called before called.
        usort($out, fn ($x, $y) => [$x['distance_km'], $x['last_called_at'] !== null]
            <=> [$y['distance_km'], $y['last_called_at'] !== null]);

        return ['candidates' => array_slice($out, 0, $limit), 'skipped' => $skipped];
    }

    // ── contact card (contact-lookup) ─────────────────────────────────────────
    public function findByPhone(string $phone): ?Applicant
    {
        return Applicant::whereNull('deleted_at')->phoneMatches($phone)->orderByDesc('id')->first();
    }

    public function contactCard(Applicant $a, string $matchedPhone): array
    {
        $a->loadMissing('jobTitle:id,name');
        $latestModule = ModuleNote::where('module_noteable_type', Applicant::class)
            ->where('module_noteable_id', $a->id)->orderByDesc('id')->value('details');
        $latestNote = ApplicantNote::where('applicant_id', $a->id)->orderByDesc('id')->first(['details', 'moved_tab_to']);
        $callbackNote = ApplicantNote::where('applicant_id', $a->id)->where('moved_tab_to', 'callback')
            ->orderByDesc('id')->value('details');

        $history = History::with('sale:id,job_title_id,sale_postcode', 'sale.jobTitle:id,name')
            ->where('applicant_id', $a->id)->orderByDesc('id')->limit(5)->get()
            ->map(fn ($h) => ['date' => optional($h->created_at)->toDateString(), 'sale_id' => $h->sale_id,
                'job' => $this->jobLabel($h->sale), 'stage' => (string) $h->stage, 'sub_stage' => (string) $h->sub_stage])
            ->values()->all();

        $applications = $this->applications($a);

        $today = now()->toDateString();
        $upcoming = Interview::where('applicant_id', $a->id)->where('schedule_date', '>=', $today)
            ->orderBy('schedule_date')->limit(5)->get();
        $past = Interview::where('applicant_id', $a->id)->where('schedule_date', '<', $today)
            ->orderByDesc('schedule_date')->limit(2)->get();
        $interviews = $upcoming->concat($past)->map(fn ($i) => ['sale_id' => $i->sale_id,
            'date' => (string) $i->schedule_date, 'time' => (string) $i->schedule_time, 'status' => (int) $i->status])
            ->values()->all();

        $calls = CallLog::with('user:id,name')->where('applicant_id', $a->id)->orderByDesc('called_at')->limit(5)->get()
            ->map(fn ($c) => ['at' => optional($c->called_at)->toIso8601ZuluString(), 'direction' => (string) $c->direction,
                'duration_s' => (int) $c->duration_seconds, 'by' => (string) optional($c->user)->name, 'sale_id' => $c->sale_id])
            ->values()->all();

        return [
            'applicant_id' => $a->id,
            'name' => (string) $a->applicant_name,
            'first_name' => Xplogent::firstName($a->applicant_name),
            'company' => '',
            'phone_kind' => $this->phoneKind($a, $matchedPhone),
            'postcode_district' => Xplogent::district($a->applicant_postcode),
            'job_title' => (string) optional($a->jobTitle)->name,
            'do_not_call' => (bool) $a->is_blocked,
            'flags' => ['blocked' => (bool) $a->is_blocked, 'temp_not_interested' => (bool) $a->is_temp_not_interested,
                'callback' => (bool) $a->is_callback_enable, 'no_response' => (bool) $a->is_no_response,
                'no_job' => (bool) $a->is_no_job, 'circuit_busy' => (bool) $a->is_circuit_busy],
            'notes' => Xplogent::clip($latestModule ?? optional($latestNote)->details, 500),
            'last_outcome' => (string) optional($latestNote)->moved_tab_to,
            'callback_note' => Xplogent::clip($callbackNote, 300),
            'cv_excerpt' => config('xplogent.share_cv_excerpt') ? $this->cv->excerpt($a) : '',
            'history' => $history,
            'applications' => $applications,
            'interviews' => $interviews,
            'calls' => $calls,
        ];
    }

    /** The candidate as the brief for a call (the Agent call page). */
    public function candidateBrief(Applicant $a, ?Sale $sale = null): ?array
    {
        $phone = $this->bestPhone($a);
        if ($phone === null) {
            return null;
        }
        $a->loadMissing('jobTitle:id,name');

        return [
            'applicant_id' => $a->id,
            'name' => (string) $a->applicant_name,
            'first_name' => Xplogent::firstName($a->applicant_name),
            'phone_e164' => $phone['e164'],
            'phone_kind' => $phone['kind'],
            'postcode_district' => Xplogent::district($a->applicant_postcode),
            'job_title' => (string) optional($a->jobTitle)->name,
            'flags' => ['callback' => (bool) $a->is_callback_enable, 'no_response' => (bool) $a->is_no_response,
                'circuit_busy' => (bool) $a->is_circuit_busy],
            'cv_excerpt' => config('xplogent.share_cv_excerpt') ? $this->cv->excerpt($a) : '',
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    /** @return array{e164: string, kind: string}|null the first number Xplogent can dial. */
    public function bestPhone($a): ?array
    {
        foreach (['mobile' => $a->applicant_phone, 'secondary' => $a->applicant_phone_secondary,
                     'landline' => $a->applicant_landline] as $kind => $number) {
            if ($e164 = Xplogent::toE164($number)) {
                return ['e164' => $e164, 'kind' => $kind];
            }
        }

        return null;
    }

    private function phoneKind($a, string $matched): string
    {
        $tail = PhoneNumber::tail($matched);
        foreach (['mobile' => $a->applicant_phone, 'secondary' => $a->applicant_phone_secondary,
                     'landline' => $a->applicant_landline] as $kind => $number) {
            if ($tail !== null && PhoneNumber::tail($number) === $tail) {
                return $kind;
            }
        }

        return 'mobile';
    }

    private function anyLocked($a, Collection $locked): bool
    {
        foreach ([$a->applicant_phone, $a->applicant_phone_secondary, $a->applicant_landline] as $number) {
            $key = DialLock::keyFor($number);
            if ($key !== null && isset($locked[$key])) {
                return true;
            }
        }

        return false;
    }

    /** "Care Assistant — LS6": the postcode district, never the client's name unless sharing is on. */
    private function jobLabel(?Sale $sale): string
    {
        if (!$sale) {
            return '';
        }
        $title = (string) optional($sale->jobTitle)->name;
        $district = Xplogent::district($sale->sale_postcode);

        return trim($title . ($district !== '' ? ' — ' . $district : ''));
    }

    private function applications(Applicant $a): array
    {
        // Vacancies this applicant is linked to: CVs sent, or a stage in history.
        $saleIds = History::where('applicant_id', $a->id)->orderByDesc('id')->pluck('sale_id')
            ->merge(CVNote::where('applicant_id', $a->id)->orderByDesc('id')->pluck('sale_id'))
            ->filter()->unique()->take(5)->values();
        $notInterested = ApplicantPivotSale::where('applicant_id', $a->id)->whereIn('sale_id', $saleIds)
            ->pluck('sale_id')->flip();

        return Sale::with('jobTitle:id,name')->whereIn('id', $saleIds)->get()->map(function (Sale $s) use ($a, $notInterested) {
            $last = History::where('applicant_id', $a->id)->where('sale_id', $s->id)->orderByDesc('id')->first(['stage', 'sub_stage']);

            return ['sale_id' => $s->id, 'job' => $this->jobLabel($s), 'interested' => !isset($notInterested[$s->id]),
                'latest_stage' => (string) optional($last)->stage, 'latest_sub_stage' => (string) optional($last)->sub_stage];
        })->values()->all();
    }
}
