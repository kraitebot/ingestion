<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Karine's Binance Credentials (local dev only)
    |--------------------------------------------------------------------------
    |
    | Used by BusinessSeeder when APP_ENV=local|testing. Karine is the
    | local-only smoke trader; production seeds bruno_nidavellir instead.
    */
    'karine' => [
        'binance_api_key' => env('TRADER_B_BINANCE_API_KEY'),
        'binance_api_secret' => env('TRADER_B_BINANCE_API_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bruno @ Nidavellir Binance Credentials (non-local seed)
    |--------------------------------------------------------------------------
    |
    | Sourced from the TRADER_BB_* env block. The .env block also carries
    | Bybit credentials but those are intentionally ignored here — this
    | trader is seeded with a Binance account only. If Bybit ever needs
    | to come back, add a second account-shape entry in BusinessSeeder
    | rather than fattening this block.
    */
    'bruno_nidavellir' => [
        'binance_api_key' => env('TRADER_BB_BINANCE_API_KEY'),
        'binance_api_secret' => env('TRADER_BB_BINANCE_API_SECRET'),
    ],
];
