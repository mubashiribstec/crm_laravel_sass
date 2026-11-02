<?php

namespace App\Http\Controllers\Api\Xplogent;

use App\Http\Controllers\Controller;
use App\Services\Xplogent\Directory;
use App\Support\Xplogent;
use Horsefly\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/xplogent/sales/open and /campaigns/queue */
class SalesController extends Controller
{
    public function __construct(private Directory $directory)
    {
    }

    public function open(Request $request): JsonResponse
    {
        $v = $request->validate(['search' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:200']);

        return Xplogent::ok(['sales' => $this->directory->openSales((string) ($v['search'] ?? ''), (int) ($v['limit'] ?? 50))]);
    }

    public function queue(Request $request): JsonResponse
    {
        $v = $request->validate([
            'sale_id' => 'required|integer',
            'radius_km' => 'nullable|numeric|min:1|max:200',
            'limit' => 'nullable|integer|min:1|max:500',
            'exclude_called_within_days' => 'nullable|integer|min:0|max:365',
        ]);
        // Without the AI user the "recently called" rule can't be applied (it is the AI's own
        // call log), and the calls' results couldn't be written back: refuse, like the writes.
        $ai = Xplogent::aiUser();
        if (!$ai) {
            return Xplogent::error(503, 'ai_user_missing', 'XPLOGENT_AI_USER_ID is not set');
        }
        $sale = Sale::withTrashed()->find((int) $v['sale_id']);
        if ($why = $this->directory->notCallable($sale)) {
            return Xplogent::error(409, 'sale_not_callable', $why);
        }
        $result = $this->directory->queue($sale, (float) ($v['radius_km'] ?? 15), (int) ($v['limit'] ?? 50),
            (int) ($v['exclude_called_within_days'] ?? 14), $ai->id);

        return Xplogent::ok(['sale' => $this->directory->saleBrief($sale)] + $result);
    }
}
