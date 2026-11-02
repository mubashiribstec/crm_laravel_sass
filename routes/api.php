<?php

use App\Http\Controllers\CallLogController;
use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authenticated user endpoint (Sanctum)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
     return $request->user();
});

// ── External portal: open / active sales feed ─────────────────────────────
// Auth: Authorization: Bearer <PORTAL_API_TOKEN>  (or X-API-Token / ?api_token=)
// Same membership rules as SaleController::getOpenSales() (status=1, not on hold).
Route::get('/sales/open', [ApiController::class, 'openSalesApi'])
     ->name('api.sales.open');

Route::get('/sales/open/kingsburypersonnel', [ApiController::class, 'openkingsburypersonnelSalesApi'])
     ->name('api.sales.open');

Route::get('/sales/details/{id}', [ApiController::class, 'getSaleDetailsApi'])
     ->name('api.sales.details');

Route::get('/regions/active', [ApiController::class, 'activeRegionsApi'])
     ->name('api.regions.active');


// ── xplosip softphone integration ─────────────────────────────────────────
// These endpoints are consumed by the floating xplosip widget embedded in the
// CRM layout. Support both Sanctum (API token) and web session authentication.
Route::middleware(['auth:sanctum,web'])->group(function () {

     // GET /api/contacts/lookup?number=07700123456
     // Returns matching applicant name + CRM URL for click-to-dial / caller ID.
     Route::get('/contacts/lookup', [CallLogController::class, 'lookup'])
          ->name('api.contacts.lookup');

     // POST /api/calls/log
     // Stores a completed call record from the xplosip widget.
     Route::post('/calls/log', [CallLogController::class, 'store'])
          ->name('api.calls.log');
});

// ── Desktop MicroSIP integration ───────────────────────────────────────────
// Token-secured (shared secret in the URL) because the desktop client cannot
// hold a Sanctum session. Set MicroSIP's crmApiUrl to:
//     https://your-crm/api/sip/<MICROSIP_API_TOKEN>
// The {token} is validated inside the controller via hash_equals.
Route::prefix('sip/{token}')->group(function () {

     // GET /api/sip/{token}/lookup?number=07700123456   (caller-ID lookup)
     Route::get('/lookup', [CallLogController::class, 'desktopLookup'])
          ->name('api.sip.lookup');

     // POST /api/sip/{token}/calls                       (call-log sink)
     Route::post('/calls', [CallLogController::class, 'desktopStore'])
          ->name('api.sip.calls');
});

// ── Xplogent (AI phone agent) ──────────────────────────────────────────────
// Signed by Xplogent with XPLOGENT_API_TOKEN (Bearer + HMAC-SHA256 V2 signature
// and timestamp), see App\Http\Middleware\VerifyXplogentSignature and
// docs/xplogent-api.md. Not related to the xplosip / MicroSIP routes above.
Route::prefix('xplogent')
     ->middleware([\App\Http\Middleware\VerifyXplogentSignature::class, 'throttle:120,1'])
     ->group(function () {
          Route::post('/sales/open', [\App\Http\Controllers\Api\Xplogent\SalesController::class, 'open']);
          Route::post('/campaigns/queue', [\App\Http\Controllers\Api\Xplogent\SalesController::class, 'queue']);
          Route::post('/contact-lookup', [\App\Http\Controllers\Api\Xplogent\ContactController::class, 'lookup']);
          Route::post('/call-summary', [\App\Http\Controllers\Api\Xplogent\CallSummaryController::class, 'store']);
          Route::post('/send-job-details', [\App\Http\Controllers\Api\Xplogent\JobDetailsController::class, 'send']);
          Route::post('/dial-lock', [\App\Http\Controllers\Api\Xplogent\DialLockController::class, 'lock']);
          Route::post('/dial-unlock', [\App\Http\Controllers\Api\Xplogent\DialLockController::class, 'unlock']);
     });
