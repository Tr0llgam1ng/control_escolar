<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Shared secret used between the API Gateway and the services.
    'internal' => [
        'key' => env('SERVICE_KEY'),
    ],

    // Used only to resolve "which student record belongs to this user" (ownership checks).
    'catalog' => [
        'url' => env('CATALOG_SERVICE_URL', 'http://127.0.0.1:8002'),
    ],

    // Services allowed to call internal-only endpoints (X-Service-Name header).
    'internal_callers' => array_filter(explode(',', (string) env('PAYMENTS_INTERNAL_CALLERS', 'enrollment-service,catalog-service,auth-service'))),

];
