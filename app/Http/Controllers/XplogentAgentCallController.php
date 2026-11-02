<?php

namespace App\Http\Controllers;

use App\Services\Xplogent\AiDialLock;
use App\Services\Xplogent\Directory;
use App\Services\Xplogent\XplogentClient;
use App\Support\Xplogent;
use Horsefly\Applicant;
use Horsefly\CallLog;
use Horsefly\Sale;
use Horsefly\XplogentCallEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * "Agent call": staff pick a vacancy and a candidate, and ask Xplogent (the AI
 * phone agent) to call. The request to Xplogent is signed server-side with the
 * shared token, which never reaches the browser.
 */
class XplogentAgentCallController extends Controller
{
    public function __construct(private Directory $directory, private AiDialLock $locks,
                                private XplogentClient $xplogent)
    {
        $this->middleware('permission:xplogent-agent-call');
    }

    public function index()
    {
        $configured = config('xplogent.api_token') !== '' && config('xplogent.base_url') !== '';

        return view('xplogent.agent-call', compact('configured'));
    }

    public function sales(Request $request): JsonResponse
    {
        return response()->json(['sales' => $this->directory->openSales((string) $request->query('search', ''), 50)]);
    }

    public function queue(Request $request): JsonResponse
    {
        $request->validate(['sale_id' => 'required|integer', 'radius_km' => 'nullable|numeric|min:1|max:200']);
        $sale = Sale::withTrashed()->find((int) $request->query('sale_id'));
        if ($why = $this->directory->notCallable($sale)) {
            return response()->json(['ok' => false, 'error' => $why], 409);
        }
        $result = $this->directory->queue($sale, (float) $request->query('radius_km', 15), 100, 0, null);
        foreach ($result['candidates'] as &$c) {
            $c['phone_display'] = \App\Support\PhoneNumber::mask($c['phone_e164']);   // masked on screen
            unset($c['phone_e164']);
        }

        return response()->json(['ok' => true, 'sale' => $this->directory->saleBrief($sale)] + $result);
    }

    /** Ask Xplogent to call one candidate about one vacancy. */
    public function dial(Request $request): JsonResponse
    {
        $request->validate(['applicant_id' => 'required|integer', 'sale_id' => 'required|integer']);
        if (!Xplogent::aiUser()) {        // the call's result would have nowhere to go
            return response()->json(['ok' => false, 'error' => 'The Xplogent AI user is not set up, so the call\'s '
                . 'result could not be saved. Run php artisan xplogent:make-ai-user, put its id in '
                . 'XPLOGENT_AI_USER_ID in .env, then php artisan config:clear.'], 503);
        }
        $applicant = Applicant::find((int) $request->input('applicant_id'));
        $sale = Sale::find((int) $request->input('sale_id'));
        if (!$applicant || !$sale) {
            return response()->json(['ok' => false, 'error' => 'unknown applicant or vacancy'], 404);
        }
        if ($applicant->is_blocked) {
            return response()->json(['ok' => false, 'error' => 'this applicant is blocked (do not call)'], 409);
        }
        if ($why = $this->directory->notCallable($sale)) {
            return response()->json(['ok' => false, 'error' => $why], 409);
        }
        $candidate = $this->directory->candidateBrief($applicant, $sale);
        if (!$candidate) {
            return response()->json(['ok' => false, 'error' => 'no number Xplogent can dial'], 422);
        }
        if ($holder = $this->locks->heldBy($candidate['phone_e164'])) {
            return response()->json(['ok' => false, 'error' => "being called by {$holder}", 'locked_by' => $holder], 409);
        }
        $r = $this->xplogent->post('/voip/crm/dial', [
            'phone' => $candidate['phone_e164'],
            'applicant_id' => $applicant->id,
            'sale_id' => $sale->id,
            'requested_by' => (string) Auth::user()->name,
            'brief' => ['sale' => $this->directory->saleBrief($sale), 'candidate' => $candidate],
        ]);
        if ($r['status'] === 0 || in_array($r['status'], [401, 404, 503], true)) {
            // Couldn't get the request to Xplogent at all: say why and how to fix it.
            $why = $this->xplogent->explain($r);
            Log::warning('xplogent dial failed: ' . $why['reason']);

            return response()->json(['ok' => false, 'error' => $why['reason'] . ' ' . $why['fix']],
                $r['error'] === 'not_configured' ? 503 : 502);
        }
        $status = $r['status'];

        return response()->json([
            'ok' => $r['ok'],
            'error' => $r['error'] ?? ($r['ok'] ? null : 'Xplogent answered HTTP ' . $status),
            'locked_by' => $r['data']['locked_by'] ?? null,
            'call' => $r['data']['call'] ?? null,
        ], $status >= 200 && $status < 300 ? 200 : ($status >= 400 && $status < 500 ? $status : 502));
    }

    /** "Test connection to Xplogent": the CRM -> Xplogent direction, nothing dialled. */
    public function check(): JsonResponse
    {
        return response()->json($this->xplogent->check('Agent call page: ' . Auth::user()->name));
    }

    /** The candidate's Xplogent call attempts (one row per call). */
    public function attempts(Request $request): JsonResponse
    {
        $request->validate(['applicant_id' => 'required|integer']);
        $showTranscript = Gate::allows('xplogent-view-transcript');
        $logs = CallLog::where('applicant_id', (int) $request->query('applicant_id'))->where('source', 'xplogent')
            ->orderByDesc('called_at')->limit(20)->get();
        $events = XplogentCallEvent::whereIn('call_log_id', $logs->pluck('id'))->get()->keyBy('call_log_id');

        return response()->json(['attempts' => $logs->map(function ($log) use ($events, $showTranscript) {
            $p = optional($events->get($log->id))->payload ?? [];

            return [
                'at' => optional($log->called_at)->toIso8601String(),
                'sale_id' => $log->sale_id,
                'outcome' => $p['outcome'] ?? '',
                'duration_s' => (int) $log->duration_seconds,
                'summary' => Xplogent::clip($p['summary'] ?? '', 600),
                'transcript' => $showTranscript ? array_slice((array) ($p['transcript'] ?? []), 0, 200) : null,
            ];
        })->values()]);
    }
}
