<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Karine's Binance Credentials (local dev only)
    |--------------------------------------------------------------------------
    |
    | Used by BusinessSeeder when APP_ENV=local. On production, only the
    | sysadmin is seeded — trader accounts are managed via the admin panel.
    */
    'karine' => [
        'binance_api_key' => env('TRADER_B_BINANCE_API_KEY'),
        'binance_api_secret' => env('TRADER_B_BINANCE_API_SECRET'),
    ],
];
