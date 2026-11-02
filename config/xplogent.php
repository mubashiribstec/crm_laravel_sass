<?php

/*
|--------------------------------------------------------------------------
| Xplogent (AI phone agent) integration
|--------------------------------------------------------------------------
| Signed API under /api/xplogent/* (see docs/xplogent-api.md) and the "Agent
| call" page. Nothing here has a real default: set the values in .env.
*/

return [
    // Shared secret (>= 32 random characters). The same value is
    // XPLOGENT_CRM_API_TOKEN on the Xplogent server; it signs both directions.
    // Empty = every /api/xplogent/* request answers 503.
    'api_token' => env('XPLOGENT_API_TOKEN', ''),

    // users.id of "Xplogent AI" (php artisan xplogent:make-ai-user). Every call
    // log, note, email and dial lock the API writes is attributed to this user.
    'ai_user_id' => env('XPLOGENT_AI_USER_ID'),

    // Xplogent's address, for the Agent call page, e.g. http://192.168.1.30:8765
    'base_url' => env('XPLOGENT_BASE_URL', ''),

    // Office / unit names are only sent to Xplogent when this is true.
    'share_client_name' => (bool) env('XPLOGENT_SHARE_CLIENT_NAME', false),

    // A redacted plain-text CV excerpt in contact-lookup and the call brief.
    // Off by default: the text is sent to the AI model's provider.
    'share_cv_excerpt' => (bool) env('XPLOGENT_SHARE_CV_EXCERPT', false),

    // Allowed clock difference for signed requests, in seconds.
    'max_skew' => (int) env('XPLOGENT_MAX_SKEW', 300),

    // Time zone for times written into notes (the app itself runs in UTC).
    'timezone' => env('XPLOGENT_TIMEZONE', 'Europe/London'),

    // send-job-details checks the address's DNS (MX/A) like other CRM forms.
    'email_dns_check' => (bool) env('XPLOGENT_EMAIL_DNS_CHECK', true),

    // Largest accepted request body, in bytes.
    'max_body' => 256 * 1024,
];
