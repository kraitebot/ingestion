<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Traders
    |--------------------------------------------------------------------------
    |
    | Business trader accounts seeded by the BusinessSeeder.
    */
    'traders' => [

        'binance_bybit' => [
            'name' => env('TRADER_BB_NAME'),
            'email' => env('TRADER_BB_EMAIL'),
            'password' => env('TRADER_BB_PASSWORD', 'password'),
            'pushover_key' => env('TRADER_BB_PUSHOVER_KEY'),
            'binance_api_key' => env('TRADER_BB_BINANCE_API_KEY'),
            'binance_api_secret' => env('TRADER_BB_BINANCE_API_SECRET'),
            'bybit_api_key' => env('TRADER_BB_BYBIT_API_KEY'),
            'bybit_api_secret' => env('TRADER_BB_BYBIT_API_SECRET'),
        ],

        'binance_only' => [
            'name' => env('TRADER_B_NAME'),
            'email' => env('TRADER_B_EMAIL'),
            'password' => env('TRADER_B_PASSWORD', 'password'),
            'pushover_key' => env('TRADER_B_PUSHOVER_KEY'),
            'binance_api_key' => env('TRADER_B_BINANCE_API_KEY'),
            'binance_api_secret' => env('TRADER_B_BINANCE_API_SECRET'),
        ],

        'kucoin' => [
            'name' => env('TRADER_KC_NAME'),
            'email' => env('TRADER_KC_EMAIL'),
            'password' => env('TRADER_KC_PASSWORD', 'password'),
            'pushover_key' => env('TRADER_KC_PUSHOVER_KEY'),
            'api_key' => env('TRADER_KC_API_KEY'),
            'api_secret' => env('TRADER_KC_API_SECRET'),
            'passphrase' => env('TRADER_KC_PASSPHRASE'),
        ],

        'bitget' => [
            'name' => env('TRADER_BG_NAME'),
            'email' => env('TRADER_BG_EMAIL'),
            'password' => env('TRADER_BG_PASSWORD', 'password'),
            'pushover_key' => env('TRADER_BG_PUSHOVER_KEY'),
            'api_key' => env('TRADER_BG_API_KEY'),
            'api_secret' => env('TRADER_BG_API_SECRET'),
            'passphrase' => env('TRADER_BG_PASSPHRASE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bybit Fallback Credentials
    |--------------------------------------------------------------------------
    |
    | Used by cleanupAccountCredentials when Bybit account is missing keys.
    */
    'bybit_fallback' => [
        'api_key' => env('BYBIT_API_KEY'),
        'api_secret' => env('BYBIT_API_SECRET'),
    ],
];
