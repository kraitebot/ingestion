<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Observers\ExchangeSymbolObserver;

beforeEach(function () {
    // Reset the cached Binance system ID between tests
    ExchangeSymbolObserver::resetBinanceSystemIdCache();
});

/**
 * Helper to create Binance API system.
 */
function createBinanceApiSystem(): ApiSystem
{
    return ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
}

/**
 * Helper to create a non-Binance API system.
 */
function createOtherExchangeApiSystem(string $canonical = 'kucoin', string $name = 'KuCoin'): ApiSystem
{
    return ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => $name,
    ]);
}

/**
 * Helper to create a tradeable Binance symbol.
 */
function createBinanceTradeable(ApiSystem $binance, string $token): ExchangeSymbol
{
    $cmcSymbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->taapiVerified()->long()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'symbol_id' => $cmcSymbol->id,
        'overlaps_with_binance' => true,
        'has_no_indicator_data' => false,
        'has_price_trend_misalignment' => false,
        'has_early_direction_change' => false,
        'has_invalid_indicator_direction' => false,
    ]);
}

// =====================================
// overlaps_with_binance auto-assignment
// =====================================

test('sets overlaps_with_binance to true for Binance symbols on creation', function () {
    $binance = createBinanceApiSystem();

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'api_system_id' => $binance->id,
    ]);

    expect($exchangeSymbol->overlaps_with_binance)->toBeTrue();
});

test('sets overlaps_with_binance to true for non-Binance symbols when token exists on Binance', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol first
    ExchangeSymbol::factory()->create([
        'token' => 'ETH',
        'api_system_id' => $binance->id,
    ]);

    // Create KuCoin symbol with same token
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'ETH',
        'api_system_id' => $kucoin->id,
    ]);

    expect($kucoinSymbol->overlaps_with_binance)->toBeTrue();
});

test('sets overlaps_with_binance to false for non-Binance symbols when token does not exist on Binance', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create KuCoin symbol with token that doesn't exist on Binance
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'UNIQUE_KUCOIN_TOKEN',
        'api_system_id' => $kucoin->id,
    ]);

    expect($kucoinSymbol->overlaps_with_binance)->toBeFalse();
});

test('sets overlaps_with_binance via TokenMapper for different token names', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol with 1000SHIB
    ExchangeSymbol::factory()->create([
        'token' => '1000SHIB',
        'api_system_id' => $binance->id,
    ]);

    // Create TokenMapper for 1000SHIB (Binance) = SHIB (KuCoin)
    TokenMapper::create([
        'binance_token' => '1000SHIB',
        'other_token' => 'SHIB',
        'other_api_system_id' => $kucoin->id,
    ]);

    // Create KuCoin symbol with SHIB
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'SHIB',
        'api_system_id' => $kucoin->id,
    ]);

    expect($kucoinSymbol->overlaps_with_binance)->toBeTrue();
});

// =====================================
// Tradeable field propagation
// =====================================

test('propagates direction change from Binance to overlapping symbols on other exchanges', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol with LONG direction
    $binanceSymbol = createBinanceTradeable($binance, 'BTC');

    // Create overlapping KuCoin symbol
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'api_system_id' => $kucoin->id,
        'overlaps_with_binance' => true,
        'direction' => 'LONG',
    ]);

    // Change Binance symbol direction to SHORT
    $binanceSymbol->direction = 'SHORT';
    $binanceSymbol->save();

    // KuCoin symbol should now have SHORT direction
    $kucoinSymbol->refresh();
    expect($kucoinSymbol->direction)->toBe('SHORT');
});

test('propagates has_invalid_indicator_direction from Binance to overlapping symbols', function () {
    $binance = createBinanceApiSystem();
    $bybit = createOtherExchangeApiSystem('bybit', 'Bybit');

    // Create Binance symbol
    $binanceSymbol = createBinanceTradeable($binance, 'ETH');

    // Create overlapping Bybit symbol
    $bybitSymbol = ExchangeSymbol::factory()->create([
        'token' => 'ETH',
        'api_system_id' => $bybit->id,
        'overlaps_with_binance' => true,
        'has_invalid_indicator_direction' => false,
    ]);

    // Invalidate the Binance symbol
    $binanceSymbol->has_invalid_indicator_direction = true;
    $binanceSymbol->direction = null;
    $binanceSymbol->save();

    // Bybit symbol should now have the invalidation flag
    $bybitSymbol->refresh();
    expect($bybitSymbol->has_invalid_indicator_direction)->toBeTrue();
    expect($bybitSymbol->direction)->toBeNull();
});

test('propagates all tradeable fields from Binance to overlapping symbols', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol with specific field values
    $binanceSymbol = createBinanceTradeable($binance, 'XRP');

    // Create overlapping KuCoin symbol with default values
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'XRP',
        'api_system_id' => $kucoin->id,
        'overlaps_with_binance' => true,
        'direction' => null,
        'indicators_values' => null,
        'indicators_timeframe' => null,
        'indicators_synced_at' => null,
        'has_no_indicator_data' => true,
        'has_price_trend_misalignment' => false,
        'has_early_direction_change' => false,
        'has_invalid_indicator_direction' => false,
    ]);

    // Update Binance symbol with new values
    $indicatorsValues = ['ema' => 50, 'rsi' => 65];
    $syncedAt = now();

    $binanceSymbol->direction = 'SHORT';
    $binanceSymbol->indicators_values = $indicatorsValues;
    $binanceSymbol->indicators_timeframe = '4h';
    $binanceSymbol->indicators_synced_at = $syncedAt;
    $binanceSymbol->has_no_indicator_data = false;
    $binanceSymbol->has_price_trend_misalignment = true;
    $binanceSymbol->has_early_direction_change = true;
    $binanceSymbol->has_invalid_indicator_direction = false;
    $binanceSymbol->save();

    // KuCoin symbol should have all the same values
    $kucoinSymbol->refresh();
    expect($kucoinSymbol->direction)->toBe('SHORT');
    expect($kucoinSymbol->indicators_values)->toEqual($indicatorsValues);
    expect($kucoinSymbol->indicators_timeframe)->toBe('4h');
    expect($kucoinSymbol->indicators_synced_at->timestamp)->toBe($syncedAt->timestamp);
    expect($kucoinSymbol->has_no_indicator_data)->toBeFalse();
    expect($kucoinSymbol->has_price_trend_misalignment)->toBeTrue();
    expect($kucoinSymbol->has_early_direction_change)->toBeTrue();
    expect($kucoinSymbol->has_invalid_indicator_direction)->toBeFalse();
});

test('propagates has_taapi_data status from Binance to overlapping symbols', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol initially without TAAPI data
    $cmcSymbol = Symbol::factory()->create(['token' => 'DOGE']);
    $binanceSymbol = ExchangeSymbol::factory()->long()->create([
        'token' => 'DOGE',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'symbol_id' => $cmcSymbol->id,
        'overlaps_with_binance' => true,
        'api_statuses' => ['has_taapi_data' => false, 'taapi_verified' => false, 'cmc_api_called' => true],
    ]);

    // Create overlapping KuCoin symbol without TAAPI data status
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'DOGE',
        'api_system_id' => $kucoin->id,
        'overlaps_with_binance' => true,
        'api_statuses' => ['has_taapi_data' => false],
    ]);

    // Update Binance symbol api_statuses AND a tradeable field (direction change triggers propagation)
    $binanceSymbol->direction = 'SHORT';
    $apiStatuses = $binanceSymbol->api_statuses;
    $apiStatuses['has_taapi_data'] = true;
    $binanceSymbol->api_statuses = $apiStatuses;
    $binanceSymbol->save();

    // KuCoin symbol should now have has_taapi_data = true
    $kucoinSymbol->refresh();
    expect($kucoinSymbol->api_statuses['has_taapi_data'])->toBeTrue();
});

test('does not propagate to symbols with overlaps_with_binance = false', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol
    $binanceSymbol = createBinanceTradeable($binance, 'LINK');

    // Create non-overlapping KuCoin symbol with UNIQUE token that doesn't exist on Binance
    // The observer automatically sets overlaps_with_binance based on token existence
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'LINK_KUCOIN_ONLY', // Different token name
        'api_system_id' => $kucoin->id,
        'direction' => 'LONG',
    ]);

    // Verify it was created with overlaps_with_binance = false (because token doesn't exist on Binance)
    expect($kucoinSymbol->overlaps_with_binance)->toBeFalse();

    // Now manually try propagation by changing direction on a Binance LINK symbol
    // that has no direct token match with KuCoin's LINK_KUCOIN_ONLY
    $binanceSymbol->direction = 'SHORT';
    $binanceSymbol->save();

    // KuCoin symbol should NOT have changed (no token match, no overlap)
    $kucoinSymbol->refresh();
    expect($kucoinSymbol->direction)->toBe('LONG');
});

test('does not propagate from non-Binance symbols', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();
    $bybit = createOtherExchangeApiSystem('bybit', 'Bybit');

    // Create Binance symbol first
    ExchangeSymbol::factory()->create([
        'token' => 'SOL',
        'api_system_id' => $binance->id,
        'direction' => null,
    ]);

    // Create KuCoin symbol
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'SOL',
        'api_system_id' => $kucoin->id,
        'overlaps_with_binance' => true,
        'direction' => 'LONG',
    ]);

    // Create Bybit symbol
    $bybitSymbol = ExchangeSymbol::factory()->create([
        'token' => 'SOL',
        'api_system_id' => $bybit->id,
        'overlaps_with_binance' => true,
        'direction' => 'LONG',
    ]);

    // Change KuCoin symbol direction - should NOT propagate to Bybit
    $kucoinSymbol->direction = 'SHORT';
    $kucoinSymbol->save();

    // Bybit symbol should NOT have changed
    $bybitSymbol->refresh();
    expect($bybitSymbol->direction)->toBe('LONG');
});

// =====================================
// TokenMapper propagation
// =====================================

test('propagates tradeable fields via TokenMapper for different token names', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol with 1000PEPE
    $binanceSymbol = createBinanceTradeable($binance, '1000PEPE');

    // Create TokenMapper
    TokenMapper::create([
        'binance_token' => '1000PEPE',
        'other_token' => 'PEPE',
        'other_api_system_id' => $kucoin->id,
    ]);

    // Create KuCoin symbol with PEPE (different name)
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'PEPE',
        'api_system_id' => $kucoin->id,
        'overlaps_with_binance' => true,
        'direction' => 'LONG',
    ]);

    // Update Binance symbol
    $binanceSymbol->direction = 'SHORT';
    $binanceSymbol->has_invalid_indicator_direction = true;
    $binanceSymbol->save();

    // KuCoin PEPE should receive the update via TokenMapper
    $kucoinSymbol->refresh();
    expect($kucoinSymbol->direction)->toBe('SHORT');
    expect($kucoinSymbol->has_invalid_indicator_direction)->toBeTrue();
});

test('propagates to multiple exchanges via TokenMapper', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem('kucoin', 'KuCoin');
    $bybit = createOtherExchangeApiSystem('bybit', 'Bybit');

    // Create TokenMappers FIRST before creating any symbols
    TokenMapper::create([
        'binance_token' => '1000XEC',
        'other_token' => 'XEC',
        'other_api_system_id' => $kucoin->id,
    ]);
    TokenMapper::create([
        'binance_token' => '1000XEC',
        'other_token' => 'XEC',
        'other_api_system_id' => $bybit->id,
    ]);

    // Create Binance symbol with 1000XEC (starts with LONG direction from createBinanceTradeable)
    $binanceSymbol = createBinanceTradeable($binance, '1000XEC');

    // Create exchange symbols on both exchanges - they should get overlaps_with_binance=true via TokenMapper
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'XEC',
        'api_system_id' => $kucoin->id,
        'direction' => null,
    ]);
    $bybitSymbol = ExchangeSymbol::factory()->create([
        'token' => 'XEC',
        'api_system_id' => $bybit->id,
        'direction' => null,
    ]);

    // Verify they were created with overlaps_with_binance = true via TokenMapper
    expect($kucoinSymbol->overlaps_with_binance)->toBeTrue();
    expect($bybitSymbol->overlaps_with_binance)->toBeTrue();

    // Update Binance symbol - change direction to SHORT (different from initial LONG)
    $binanceSymbol->direction = 'SHORT';
    $binanceSymbol->save();

    // Both should receive the update
    $kucoinSymbol->refresh();
    $bybitSymbol->refresh();
    expect($kucoinSymbol->direction)->toBe('SHORT');
    expect($bybitSymbol->direction)->toBe('SHORT');
});

// =====================================
// Default api_statuses on creation
// =====================================

test('sets default api_statuses on creation when symbol_id is null', function () {
    $binance = createBinanceApiSystem();

    $symbol = ExchangeSymbol::factory()->create([
        'token' => 'NEW_TOKEN',
        'api_system_id' => $binance->id,
        'symbol_id' => null,
    ]);

    expect($symbol->api_statuses['cmc_api_called'])->toBeFalse();
    expect($symbol->api_statuses['taapi_verified'])->toBeFalse();
    expect($symbol->api_statuses['has_taapi_data'])->toBeFalse();
});

test('sets cmc_api_called to true when symbol_id is provided on creation', function () {
    $binance = createBinanceApiSystem();
    $cmcSymbol = Symbol::factory()->create(['token' => 'BTC']);

    $symbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'api_system_id' => $binance->id,
        'symbol_id' => $cmcSymbol->id,
    ]);

    expect($symbol->api_statuses['cmc_api_called'])->toBeTrue();
});

// =====================================
// Edge cases
// =====================================

test('handles propagation when no overlapping symbols exist', function () {
    $binance = createBinanceApiSystem();

    // Create Binance symbol with no overlapping symbols on other exchanges
    $binanceSymbol = createBinanceTradeable($binance, 'UNIQUE_BTC');

    // This should not throw any errors
    $binanceSymbol->direction = 'SHORT';
    $binanceSymbol->save();

    $binanceSymbol->refresh();
    expect($binanceSymbol->direction)->toBe('SHORT');
});

test('only propagates when tradeable fields actually changed', function () {
    $binance = createBinanceApiSystem();
    $kucoin = createOtherExchangeApiSystem();

    // Create Binance symbol
    $binanceSymbol = createBinanceTradeable($binance, 'ADA');

    // Create overlapping KuCoin symbol
    $kucoinSymbol = ExchangeSymbol::factory()->create([
        'token' => 'ADA',
        'api_system_id' => $kucoin->id,
        'overlaps_with_binance' => true,
        'direction' => 'LONG',
        'min_notional' => 5.00,
    ]);

    $originalDirection = $kucoinSymbol->direction;

    // Update Binance symbol with non-tradeable field only (min_notional is not in TRADEABLE_FIELDS)
    $binanceSymbol->min_notional = 999.99;
    $binanceSymbol->save();

    // KuCoin symbol's direction should NOT have been updated (no tradeable field changed)
    $kucoinSymbol->refresh();
    expect($kucoinSymbol->direction)->toBe($originalDirection);
});
