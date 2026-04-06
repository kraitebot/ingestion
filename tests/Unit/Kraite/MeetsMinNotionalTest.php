<?php

declare(strict_types=1);

use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Trading\Kraite;

/**
 * Helper to create a candle with current_price for an ExchangeSymbol.
 */
function createCandleWithPrice(ExchangeSymbol $symbol, string $price): Candle
{
    return Candle::create([
        'exchange_symbol_id' => $symbol->id,
        'timeframe' => '5m',
        'timestamp' => time(),
        'candle_time_utc' => now(),
        'candle_time_local' => now(),
        'open' => $price,
        'high' => $price,
        'low' => $price,
        'close' => $price,
        'volume' => '1000.00',
    ]);
}

/*
|--------------------------------------------------------------------------
| Binance/Bybit/BitGet Tests (Direct min_notional)
|--------------------------------------------------------------------------
*/

test('meetsMinNotional returns true when amount equals min_notional', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => 10.00,
    ]);

    expect(Kraite::meetsMinNotional($symbol, 10.00))->toBeTrue();
});

test('meetsMinNotional returns true when amount exceeds min_notional', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => 10.00,
    ]);

    expect(Kraite::meetsMinNotional($symbol, 15.00))->toBeTrue();
});

test('meetsMinNotional returns false when amount is below min_notional', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => 10.00,
    ]);

    expect(Kraite::meetsMinNotional($symbol, 9.99))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| KuCoin Tests (lot_size * multiplier * price)
|--------------------------------------------------------------------------
*/

test('meetsMinNotional returns true for KuCoin when amount meets calculated min', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => 1.0,
        'kucoin_multiplier' => 0.001, // Each contract = 0.001 BTC
    ]);

    // min_order = 1 * 0.001 * 50000 = $50
    createCandleWithPrice($symbol, '50000.00');

    expect(Kraite::meetsMinNotional($symbol, 50.00))->toBeTrue();
    expect(Kraite::meetsMinNotional($symbol, 100.00))->toBeTrue();
});

test('meetsMinNotional returns false for KuCoin when amount is below calculated min', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => 1.0,
        'kucoin_multiplier' => 0.001,
    ]);

    // min_order = 1 * 0.001 * 50000 = $50
    createCandleWithPrice($symbol, '50000.00');

    expect(Kraite::meetsMinNotional($symbol, 49.99))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

test('meetsMinNotional returns false when symbol has no min order data', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => null,
        'kucoin_multiplier' => null,
    ]);

    expect(Kraite::meetsMinNotional($symbol, 100.00))->toBeFalse();
});

test('meetsMinNotional returns false for KuCoin without current_price', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => 1.0,
        'kucoin_multiplier' => 0.001,
    ]);

    // No candle created - no current_price available

    expect(Kraite::meetsMinNotional($symbol, 100.00))->toBeFalse();
});

test('meetsMinNotional returns false for KuCoin with only lot_size (missing multiplier)', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => 1.0,
        'kucoin_multiplier' => null,
    ]);

    createCandleWithPrice($symbol, '50000.00');

    expect(Kraite::meetsMinNotional($symbol, 100.00))->toBeFalse();
});

test('meetsMinNotional accepts string amounts', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => 10.00,
    ]);

    expect(Kraite::meetsMinNotional($symbol, '15.00'))->toBeTrue();
    expect(Kraite::meetsMinNotional($symbol, '5.00'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| hasMinOrderRequirements Tests
|--------------------------------------------------------------------------
*/

test('hasMinOrderRequirements returns true for symbol with min_notional', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => 10.00,
    ]);

    expect(Kraite::hasMinOrderRequirements($symbol))->toBeTrue();
});

test('hasMinOrderRequirements returns true for KuCoin symbol with current_price', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => 1.0,
        'kucoin_multiplier' => 0.001,
    ]);

    createCandleWithPrice($symbol, '50000.00');

    expect(Kraite::hasMinOrderRequirements($symbol))->toBeTrue();
});

test('hasMinOrderRequirements returns false for symbol without any min order data', function () {
    $symbol = ExchangeSymbol::factory()->create([
        'min_notional' => null,
        'kucoin_lot_size' => null,
        'kucoin_multiplier' => null,
    ]);

    expect(Kraite::hasMinOrderRequirements($symbol))->toBeFalse();
});
