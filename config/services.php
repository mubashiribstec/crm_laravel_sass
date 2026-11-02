<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    /*
    | Shared secret for external portal integrations (e.g. open sales feed).
    | Send as: Authorization: Bearer <PORTAL_API_TOKEN>
    | or header X-API-Token / query ?api_token=
    */
    'portal' => [
        'token' => 'v8Kq2mZ7xP4nR9tL6wY3cF1sD5hJ0uA8eG2bN7qX9mV4pT6z',
    ],

    'microsip' => [
        'token' => env('MICROSIP_API_TOKEN'),
    ],

    // Inbound SMS from the GSM gateway (GET /message_receive?token=...). Empty = refused.
    'sms_webhook' => [
        'token' => env('SMS_WEBHOOK_TOKEN', ''),
    ],

];
