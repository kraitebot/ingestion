<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Engine;
use Kraite\Core\Notifications\AlertNotification;

// Test case and RefreshDatabase configured in Pest.php for Integration folder

// Test server_rate_limit_exceeded notification
it('sends server_rate_limit_exceeded notification when API returns 429', function () {
    // Enable notifications globally for this test
    config(['kraite.notifications_enabled' => true]);

    // Fake notifications to prevent actual sending
    Notification::fake();

    // Create the notification canonical definition (required by observer)
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create();

    // Create API system
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
        'is_exchange' => true,
    ]);

    // Create Engine record (singleton) for admin notifications
    $admin = Engine::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Create API request log with 429 status code (triggers notification via observer)
    ApiRequestLog::factory()
        ->rateLimited()
        ->create([
            'api_system_id' => $apiSystem->id,
            'http_method' => 'GET',
            'path' => '/api/v3/ticker/price',
        ]);

    // Assert notification was sent to admin
    Notification::assertSentTo(
        Engine::admin(),
        AlertNotification::class,
        function ($notification, $channels) {
            // Verify notification contains correct canonical and message
            return $notification->canonical === 'server_rate_limit_exceeded'
                && str_contains($notification->message, 'Binance')
                && str_contains($notification->message, '429');
        }
    );
});

// Test server_ip_forbidden notification
it('sends server_ip_forbidden notification when API returns 418', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create the notification canonical definition (required by observer)
    \Kraite\Core\Models\Notification::factory()
        ->serverIpForbidden()
        ->create();

    // Create API system
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
        'is_exchange' => true,
    ]);

    // Create Engine record (singleton) for admin notifications
    Engine::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Create API request log with 418 status code (triggers notification via observer)
    ApiRequestLog::factory()->create([
        'api_system_id' => $apiSystem->id,
        'http_response_code' => 418,
        'http_method' => 'GET',
        'path' => '/api/v3/account',
    ]);

    // Assert notification was sent to admin
    Notification::assertSentTo(
        Engine::admin(),
        AlertNotification::class,
        function ($notification) {
            return $notification->canonical === 'server_ip_forbidden'
                && str_contains($notification->message, 'Binance')
                && str_contains($notification->message, '418');
        }
    );
});

// Test exchange_symbol_no_taapi_data notification
it('sends exchange_symbol_no_taapi_data notification when TAAPI consistently returns no data error', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create the notification canonical definition (required by observer)
    \Kraite\Core\Models\Notification::factory()
        ->exchangeSymbolNoTaapiData()
        ->create();

    // Create TAAPI API system
    $taapiSystem = ApiSystem::factory()->create([
        'canonical' => 'taapi',
        'name' => 'TAAPI.IO',
        'is_exchange' => false,
    ]);

    // Create exchange API system (Bybit)
    $exchangeSystem = ApiSystem::factory()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
        'is_exchange' => true,
    ]);

    // Create Symbol and ExchangeSymbol
    // Use unique() to avoid unique constraint violations on token
    $tokenName = 'TEST'.uniqid();
    $quoteName = 'USDT';
    $symbol = \Kraite\Core\Models\Symbol::factory()->create([
        'token' => $tokenName,
    ]);

    $exchangeSymbol = \Kraite\Core\Models\ExchangeSymbol::factory()->create([
        'token' => $tokenName,
        'quote' => $quoteName,
        'symbol_id' => $symbol->id,
        'api_system_id' => $exchangeSystem->id,
        'is_manually_enabled' => true,
        'api_statuses' => [
            'cmc_api_called' => true,
            'taapi_verified' => true,
            'has_taapi_data' => true, // Must be true for observer to trigger notification
        ],
    ]);

    // Create Engine record (singleton) for admin notifications
    Engine::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Create payload that TAAPI uses (matches the pattern in observer)
    $payload = [
        'options' => [
            'exchange' => 'bybit',
            'symbol' => "{$tokenName}/{$quoteName}",
        ],
    ];

    // Create 3 failed API request logs with "no data" error (creates consistent pattern)
    // Observer requires 3+ consecutive failures to trigger notification
    for ($i = 0; $i < 3; $i++) {
        ApiRequestLog::factory()->create([
            'api_system_id' => $taapiSystem->id,
            'http_response_code' => 400,
            'http_method' => 'GET',
            'path' => '/rsi',
            'payload' => $payload,
            'response' => ['error' => 'No candles were found!'],
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    // Final request that triggers the notification (4th failure)
    ApiRequestLog::factory()->create([
        'api_system_id' => $taapiSystem->id,
        'http_response_code' => 400,
        'http_method' => 'GET',
        'path' => '/rsi',
        'payload' => $payload,
        'response' => ['error' => 'No candles were found!'],
    ]);

    // Assert notification was sent to admin
    Notification::assertSentTo(
        Engine::admin(),
        AlertNotification::class,
        function ($notification) {
            return $notification->canonical === 'exchange_symbol_no_taapi_data';
        }
    );

    // Assert exchange symbol was flagged as having no indicator data
    $exchangeSymbol->refresh();
    expect($exchangeSymbol->has_no_indicator_data)->toBeTrue();
    expect($exchangeSymbol->api_statuses['has_taapi_data'])->toBeFalse();
});

// Test token_delisting notification
it('sends token_delisting notification when token is delisted', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create the notification canonical definition
    \Kraite\Core\Models\Notification::factory()
        ->tokenDelisting()
        ->create();

    // Create API system
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
        'is_exchange' => true,
    ]);

    // Create exchange symbol
    $tokenName = 'TEST'.uniqid();
    $symbol = \Kraite\Core\Models\Symbol::factory()->create([
        'token' => $tokenName,
    ]);

    $exchangeSymbol = \Kraite\Core\Models\ExchangeSymbol::factory()->create([
        'token' => $tokenName,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create Engine record (singleton) for admin notifications
    Engine::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Trigger notification via NotificationService
    \Kraite\Core\Support\NotificationService::send(
        user: Engine::admin(),
        canonical: 'token_delisting',
        referenceData: [
            'apiSystem' => $apiSystem,
            'exchangeSymbol' => $exchangeSymbol,
            'pair_text' => $exchangeSymbol->parsed_trading_pair ?? 'TEST/USDT',
            'delivery_date' => '31 Dec 2025 08:00',
            'positions_count' => 0,
            'positions_details' => '',
        ],
        relatable: $exchangeSymbol,
        cacheKeys: [
            'exchange_symbol' => $exchangeSymbol->id,
        ]
    );

    // Assert notification was sent to admin
    Notification::assertSentTo(
        Engine::admin(),
        AlertNotification::class,
        function ($notification) use ($exchangeSymbol) {
            $pairText = $exchangeSymbol->parsed_trading_pair ?? 'TEST/USDT';

            return $notification->canonical === 'token_delisting'
                && str_contains($notification->message, $pairText)
                && str_contains($notification->message, '31 Dec 2025');
        }
    );
});
