<?php

namespace App\Http\Controllers\Api\Xplogent;

use App\Http\Controllers\Controller;
use App\Services\Xplogent\VacancyEmailService;
use App\Support\Xplogent;
use Horsefly\Applicant;
use Horsefly\Sale;
use Horsefly\SentEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/xplogent/send-job-details: queue the vacancy email for one candidate. */
class JobDetailsController extends Controller
{
    public const PER_DAY = 3;

    public function __construct(private VacancyEmailService $emails)
    {
    }

    public function send(Request $request): JsonResponse
    {
        $v = $request->validate([
            'applicant_id' => 'required|integer',
            'sale_id' => 'required|integer',
            'email' => ['required', 'string', 'max:191', config('xplogent.email_dns_check', true) ? 'email:rfc,dns' : 'email:rfc'],
            'call_id' => 'nullable|integer',
        ]);
        $ai = Xplogent::aiUser();
        if (!$ai) {
            return Xplogent::error(503, 'ai_user_missing', 'XPLOGENT_AI_USER_ID is not set');
        }
        $applicant = Applicant::find((int) $v['applicant_id']);
        $sale = Sale::find((int) $v['sale_id']);
        if (!$applicant || !$sale) {
            return Xplogent::error(404, 'not_found', 'unknown applicant or vacancy');
        }
        if ($applicant->is_blocked) {
            return Xplogent::error(409, 'blocked', 'this applicant is blocked');
        }
        $today = SentEmail::where('applicant_id', $applicant->id)->where('user_id', $ai->id)
            ->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= self::PER_DAY) {
            return Xplogent::error(429, 'rate_limited', 'at most ' . self::PER_DAY . ' vacancy emails per applicant per day');
        }
        $filled = $this->emails->fill($sale, $ai->name);
        if (!$filled) {
            return Xplogent::error(503, 'template_missing', 'the send_job_vacancy_details email template is missing or inactive');
        }
        $sent = $this->emails->queue($filled, $v['email'], $applicant->id, $sale->id, $ai);

        return Xplogent::ok(['ok' => true, 'sent_email_id' => $sent->id]);
    }
}
