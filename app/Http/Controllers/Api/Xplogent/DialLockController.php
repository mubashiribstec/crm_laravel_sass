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
            'ttl_seconds' => 'nullable|integer|min:1|max:' . AiDialLock::MAX_TTL,
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

    /**
     * 200 released: true; 200 released: false when there is no lock left (it expired, or the
     * call summary already ended it), so releasing twice is harmless; 409 not_your_lock when
     * someone else's lock is active; 503 without the AI user.
     */
    public function unlock(Request $request): JsonResponse
    {
        $v = $request->validate(['phone' => 'required|string|max:40', 'lock_token' => 'required|string|max:64']);
        $ai = Xplogent::aiUser();
        if (!$ai) {
            return Xplogent::error(503, 'ai_user_missing', 'XPLOGENT_AI_USER_ID is not set');
        }
        $r = $this->locks->unlock($v['phone'], $v['lock_token'], $ai);
        if (!$r['released'] && $r['locked_by'] !== null) {
            return Xplogent::error(409, 'not_your_lock', "this number's lock belongs to someone else",
                ['released' => false, 'locked_by' => $r['locked_by']]);
        }

        return Xplogent::ok(['released' => $r['released']]);
    }
}
