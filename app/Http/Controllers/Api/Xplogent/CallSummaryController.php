<?php

namespace App\Http\Controllers\Api\Xplogent;

use App\Http\Controllers\Controller;
use App\Services\Xplogent\CallSummaryWriter;
use App\Support\Xplogent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/xplogent/call-summary: write a finished call back (idempotent on call_id). */
class CallSummaryController extends Controller
{
    public function __construct(private CallSummaryWriter $writer)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'call_id' => 'required|integer|min:0',
            'test' => 'nullable|boolean',
            'outcome' => 'nullable|string|max:40',
            'direction' => 'nullable|string|max:20',
            'applicant_id' => 'nullable|integer',
            'sale_id' => 'nullable|integer',
            'duration_s' => 'nullable|numeric|min:0',
            'summary' => 'nullable|string|max:20000',
            'answers' => 'nullable|array',
            'transcript' => 'nullable|array',
        ]);
        if ($request->boolean('test')) {
            return Xplogent::ok(['ok' => true, 'test' => true]);
        }
        $ai = Xplogent::aiUser();
        if (!$ai) {
            return Xplogent::error(503, 'ai_user_missing', 'XPLOGENT_AI_USER_ID is not set: run php artisan xplogent:make-ai-user');
        }
        $result = $this->writer->write($request->json()->all(), $ai);
        if ($result['duplicate']) {
            return Xplogent::ok(['ok' => true, 'duplicate' => true]);
        }

        return Xplogent::ok(['call_log_id' => $result['call_log_id'], 'lock_released' => $result['lock_released'] ?? false]);
    }
}
