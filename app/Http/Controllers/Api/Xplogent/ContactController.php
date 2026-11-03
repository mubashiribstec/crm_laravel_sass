<?php

namespace App\Http\Controllers\Api\Xplogent;

use App\Http\Controllers\Controller;
use App\Services\Xplogent\Directory;
use App\Support\Xplogent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/xplogent/contact-lookup: who is this caller / candidate. */
class ContactController extends Controller
{
    public function __construct(private Directory $directory)
    {
    }

    public function lookup(Request $request): JsonResponse
    {
        $v = $request->validate([
            'phone' => 'required|string|max:40',
            'direction' => 'nullable|string|max:20',
            'line' => 'nullable|string|max:40',
            'original_called_number' => 'nullable|string|max:40',
            'sale_id' => 'nullable|integer',
        ]);
        $applicant = $this->directory->findByPhone($v['phone']);
        if (!$applicant) {
            return Xplogent::ok(['ok' => true, 'found' => false], 404);
        }

        return Xplogent::ok(['found' => true, 'contact' => $this->directory->contactCard($applicant, $v['phone'])]);
    }
}
