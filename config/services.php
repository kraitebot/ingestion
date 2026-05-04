<?php

declare(strict_types=1);

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

    'pushover' => [
        'token' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
    ],

    'telegram-bot-api' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'zeptomail' => [
        'mail_key' => env('ZEPTOMAIL_MAIL_KEY'),
        'endpoint' => env('ZEPTO_MAIL_ENDPOINT', 'https://api.zeptomail.com'),
        'timeout' => env('ZEPTO_MAIL_TIMEOUT', 30),
        'retries' => env('ZEPTO_MAIL_RETRIES', 2),
        'retry_sleep_ms' => env('ZEPTO_MAIL_RETRY_MS', 200),
        'template_key' => env('ZEPTO_MAIL_TEMPLATE_KEY'),
        'template_alias' => env('ZEPTO_MAIL_TEMPLATE_ALIAS'),
        'bounce_address' => env('ZEPTO_MAIL_BOUNCE_ADDRESS'),
        'track_opens' => env('ZEPTO_MAIL_TRACK_OPENS', true),
        'track_clicks' => env('ZEPTO_MAIL_TRACK_CLICKS', true),
        'client_reference' => env('ZEPTO_MAIL_CLIENT_REFERENCE'),
        'force_batch' => env('ZEPTO_MAIL_FORCE_BATCH', false),
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

];
