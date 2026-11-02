<?php

namespace App\Http\Controllers\Api\Xplogent;

use App\Http\Controllers\Controller;
use App\Services\Xplogent\AiDialLock;
use App\Support\Xplogent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/xplogent/dial-lock and /dial-unlock */
class DialLockController extends Controller
{
    public function __construct(private AiDialLock $locks)
    {
    }

    public function lock(Request $request): JsonResponse
    {
        $v = $request->validate([
            'phone' => 'required|string|max:40',
            'applicant_id' => 'nullable|integer',
            'ttl_seconds' => 'nullable|integer|min:1|max:86400',
            'lock_token' => 'required|string|min:8|max:64',
        ]);
        $ai = Xplogent::aiUser();
        if (!$ai) {
            return Xplogent::error(503, 'ai_user_missing', 'XPLOGENT_AI_USER_ID is not set');
        }
        $r = $this->locks->lock($v['phone'], isset($v['applicant_id']) ? (int) $v['applicant_id'] : null,
            (int) ($v['ttl_seconds'] ?? 900), $v['lock_token'], $ai);

        return Xplogent::ok($r['body'], $r['status']);
    }

    public function unlock(Request $request): JsonResponse
    {
        $v = $request->validate(['phone' => 'required|string|max:40', 'lock_token' => 'nullable|string|max:64']);
        $ai = Xplogent::aiUser();
        $released = $ai ? $this->locks->unlock($v['phone'], (string) ($v['lock_token'] ?? ''), $ai) : false;

        return Xplogent::ok(['ok' => true, 'released' => $released]);
    }
}
