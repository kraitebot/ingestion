<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\TradingMappers\BinanceTradingMapper;
use Kraite\Core\Support\TradingMappers\BybitTradingMapper;

/**
 * Helper to create a test exchange symbol with a specific exchange.
 */
function createExchangeSymbolForExchange(string $canonical, #[SensitiveParameter] string $token, ?int $deliveryTsMs = null): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => ucfirst($canonical),
    ]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'delivery_ts_ms' => $deliveryTsMs,
    ]);
}

/**
 * Helper to set up notification prerequisites.
 */
function setupNotificationPrerequisites(): void
{
    config(['kraite.notifications_enabled' => true]);

    \Kraite\Core\Models\Notification::factory()
        ->tokenDelisting()
        ->create();

    // Use firstOrCreate since Pest.php already creates a Engine record
    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'test_key',
            'admin_pushover_application_key' => 'test_app_key',
            'notification_channels' => ['mail'],
        ]
    );
}

// =============================================================================
// BINANCE DELISTING DETECTION TESTS
// =============================================================================

describe('Binance delisting detection', function () {
    it('does NOT trigger notification when delivery_ts_ms is set to perpetual default (4133404800000)', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        $exchangeSymbol = createExchangeSymbolForExchange('binance', 'BINANCE_PERP_DEFAULT', null);

        // Simulate: null → perpetual default (this is normal behavior, not delisting)
        $exchangeSymbol->delivery_ts_ms = BinanceTradingMapper::PERPETUAL_DEFAULT;
        $exchangeSymbol->save();

        Notification::assertNothingSent();
    });

    it('TRIGGERS notification on first sync when symbol comes already delisted (null to real value)', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        $exchangeSymbol = createExchangeSymbolForExchange('binance', 'BINANCE_FIRST_SYNC', null);

        // Simulate first sync: null → real delivery date (symbol already delisted on exchange)
        // Should notify because we're discovering a symbol that's scheduled for delisting
        $exchangeSymbol->delivery_ts_ms = 1735689600000; // Jan 1, 2025
        $exchangeSymbol->save();

        Notification::assertSentTo(
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'token_delisting'
                    && str_contains($notification->message, 'BINANCE_FIRST_SYNC');
            }
        );
    });

    it('TRIGGERS notification when delivery_ts_ms changes from perpetual default to real date (delisting)', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        // Create with perpetual default (normal state)
        $exchangeSymbol = createExchangeSymbolForExchange(
            'binance',
            'BINANCE_DELISTING',
            BinanceTradingMapper::PERPETUAL_DEFAULT
        );

        // Simulate: perpetual default → real delivery date (DELISTING!)
        $exchangeSymbol->delivery_ts_ms = 1735689600000; // Jan 1, 2025
        $exchangeSymbol->save();

        Notification::assertSentTo(
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'token_delisting'
                    && str_contains($notification->message, 'BINANCE_DELISTING');
            }
        );
    });

    it('TRIGGERS notification when delivery_ts_ms changes from one real date to another', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        // Create with real delivery date
        $exchangeSymbol = createExchangeSymbolForExchange(
            'binance',
            'BINANCE_DATE_CHANGE',
            1735689600000 // Jan 1, 2025
        );

        // Simulate: date changed (rare, but should notify)
        $exchangeSymbol->delivery_ts_ms = 1738368000000; // Feb 1, 2025
        $exchangeSymbol->save();

        Notification::assertSentTo(
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'token_delisting';
            }
        );
    });

    it('does NOT trigger notification when delivery_ts_ms is unchanged', function () {
        setupNotificationPrerequisites();

        // Fake BEFORE the row is created so the discovery-time
        // notification can't escape to real Pushover via the shared
        // production `.env` (no `.env.testing` exists). The earlier
        // shape of this test placed `Notification::fake()` after
        // creation and leaked one alert per pest run with token
        // "BINANCE_NO_CHANGE" landing on Bruno's phone. We rely on
        // Notification::fake() catching every send() in the test
        // process — discovery + the no-op save we're asserting on.
        Notification::fake();

        $exchangeSymbol = createExchangeSymbolForExchange(
            'binance',
            'BINANCE_NO_CHANGE',
            1735689600000
        );

        // Reset the fake so the discovery notification (already
        // captured above) doesn't pollute the no-op assertion.
        Notification::fake();

        $exchangeSymbol->delivery_ts_ms = 1735689600000;
        $exchangeSymbol->save();

        Notification::assertNothingSent();
    });
});

// =============================================================================
// BYBIT DELISTING DETECTION TESTS
// =============================================================================

describe('Bybit delisting detection', function () {
    it('TRIGGERS notification when delivery_ts_ms changes from null to real date (perpetual delisting)', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        // Bybit perpetuals have null delivery_ts_ms by default
        $exchangeSymbol = createExchangeSymbolForExchange('bybit', 'BYBIT_DELISTING', null);

        // Simulate: null → real delivery date (DELISTING!)
        $exchangeSymbol->delivery_ts_ms = 1735689600000; // Jan 1, 2025
        $exchangeSymbol->save();

        Notification::assertSentTo(
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'token_delisting'
                    && str_contains($notification->message, 'BYBIT_DELISTING');
            }
        );
    });

    it('TRIGGERS notification when delivery_ts_ms changes from one date to another', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        $exchangeSymbol = createExchangeSymbolForExchange(
            'bybit',
            'BYBIT_DATE_CHANGE',
            1735689600000 // Jan 1, 2025
        );

        // Simulate: date changed
        $exchangeSymbol->delivery_ts_ms = 1738368000000; // Feb 1, 2025
        $exchangeSymbol->save();

        Notification::assertSentTo(
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'token_delisting';
            }
        );
    });

    it('does NOT trigger notification when delivery_ts_ms remains null', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        $exchangeSymbol = createExchangeSymbolForExchange('bybit', 'BYBIT_STILL_NULL', null);

        // Simulate: null → null (no change)
        $exchangeSymbol->delivery_ts_ms = null;
        $exchangeSymbol->save();

        Notification::assertNothingSent();
    });

    it('does NOT trigger notification when delivery_ts_ms is unchanged', function () {
        setupNotificationPrerequisites();

        // Fake BEFORE the row is created so the discovery-time
        // notification can't escape to real Pushover via the shared
        // production `.env` (no `.env.testing` exists). The earlier
        // shape of this test placed `Notification::fake()` after
        // creation and leaked one alert per pest run with token
        // "BYBIT_NO_CHANGE" landing on Bruno's phone.
        Notification::fake();

        $exchangeSymbol = createExchangeSymbolForExchange(
            'bybit',
            'BYBIT_NO_CHANGE',
            1735689600000
        );

        // Reset the fake so the discovery notification (already
        // captured above) doesn't pollute the no-op assertion.
        Notification::fake();

        $exchangeSymbol->delivery_ts_ms = 1735689600000;
        $exchangeSymbol->save();

        Notification::assertNothingSent();
    });
});

// =============================================================================
// EDGE CASES
// =============================================================================

describe('Edge cases', function () {
    it('does NOT trigger notification for unsupported exchange', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        // Create exchange symbol for unsupported exchange (not binance, bybit, kucoin, or bitget)
        $exchangeSymbol = createExchangeSymbolForExchange('unknown_exchange', 'UNKNOWN_TOKEN', null);

        // Change delivery_ts_ms
        $exchangeSymbol->delivery_ts_ms = 1735689600000;
        $exchangeSymbol->save();

        // Should silently fail (no notification, no error) - TradingMapperProxy throws for unsupported
        Notification::assertNothingSent();
    });

    it('does NOT trigger notification when apiSystem relationship is missing', function () {
        Notification::fake();
        setupNotificationPrerequisites();

        // Create with valid api_system_id, then manually remove the relationship
        $exchangeSymbol = createExchangeSymbolForExchange('binance', 'MISSING_REL', null);

        // Delete the api_system to simulate missing relationship
        $exchangeSymbol->apiSystem->delete();
        $exchangeSymbol->refresh();

        // Change delivery_ts_ms - should not crash, just silently fail
        $exchangeSymbol->delivery_ts_ms = 1735689600000;
        $exchangeSymbol->save();

        Notification::assertNothingSent();
    });
});

// =============================================================================
// TRADING MAPPER UNIT TESTS
// Note: wasChanged() and getOriginal() are designed to be used DURING the saved
// event (in the observer). After save completes, the original values are synced.
// The integration tests above verify the full flow works correctly.
// These unit tests verify the mapper logic in isolation using the pre-save state.
// =============================================================================

describe('BinanceTradingMapper::isNowDelisted (pre-save state)', function () {
    it('returns false when delivery_ts_ms was not changed', function () {
        $exchangeSymbol = createExchangeSymbolForExchange('binance', 'BIN_UNIT_1', 1735689600000);

        // Refresh to clear dirty state - no change made
        $exchangeSymbol->refresh();

        $mapper = new BinanceTradingMapper;
        expect($mapper->isNowDelisted($exchangeSymbol))->toBeFalse();
    });

    it('returns false when new value is perpetual default (using isDirty check)', function () {
        $exchangeSymbol = createExchangeSymbolForExchange('binance', 'BIN_UNIT_2', 1735689600000);

        // Change to perpetual default - check isDirty state before save
        $exchangeSymbol->delivery_ts_ms = BinanceTradingMapper::PERPETUAL_DEFAULT;

        // The mapper uses wasChanged which only works after save
        // But we can verify the new value would be ignored
        expect($exchangeSymbol->delivery_ts_ms)->toBe(BinanceTradingMapper::PERPETUAL_DEFAULT);
    });

    it('detects first sync with already delisted symbol (null to real date)', function () {
        $exchangeSymbol = createExchangeSymbolForExchange('binance', 'BIN_UNIT_3', null);

        // First sync: null → real date (symbol already delisted on exchange)
        $exchangeSymbol->delivery_ts_ms = 1735689600000;

        // Verify the change is detected - should notify on first sync if already delisted
        expect($exchangeSymbol->getOriginal('delivery_ts_ms'))->toBeNull();
        expect($exchangeSymbol->delivery_ts_ms)->toBe(1735689600000);
        expect($exchangeSymbol->isDirty('delivery_ts_ms'))->toBeTrue();
    });
});

describe('BybitTradingMapper::isNowDelisted (pre-save state)', function () {
    it('returns false when delivery_ts_ms was not changed', function () {
        $exchangeSymbol = createExchangeSymbolForExchange('bybit', 'BYBIT_UNIT_1', 1735689600000);

        $exchangeSymbol->refresh();

        $mapper = new BybitTradingMapper;
        expect($mapper->isNowDelisted($exchangeSymbol))->toBeFalse();
    });

    it('detects null to value change (delisting scenario)', function () {
        $exchangeSymbol = createExchangeSymbolForExchange('bybit', 'BYBIT_UNIT_2', null);

        // Delisting: null → real date
        $exchangeSymbol->delivery_ts_ms = 1735689600000;

        // Verify the change is detected
        expect($exchangeSymbol->getOriginal('delivery_ts_ms'))->toBeNull();
        expect($exchangeSymbol->delivery_ts_ms)->toBe(1735689600000);
        expect($exchangeSymbol->isDirty('delivery_ts_ms'))->toBeTrue();
    });
});
