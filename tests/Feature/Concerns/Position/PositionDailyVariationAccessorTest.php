<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the `daily_variation_percentage` accessor on Position.
 *
 * The accessor reads the latest 1d dashboard candle row for the
 * symbol, extracts the previous-day open from `data.open[0]`, and
 * returns the percentage move from that open to the current price.
 * Two stamps before the BCMath migration so any precision drift in
 * the rewrite shows up here, not in production data.
 */
function seedDashboardCandleIndicator(): Indicator
{
    return Indicator::query()->firstOrCreate(
        ['canonical' => 'candle', 'type' => 'dashboard'],
        [
            'is_active' => true,
            'class' => Kraite\Core\Indicators\History\CandleIndicator::class,
        ]
    );
}

function makePositionWith1dOpen(string $token, string $previousOpen, string $currentPrice): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Seed a fresh 5m candle so the `current_price` accessor returns
    // the test's chosen price instead of null (the accessor reads the
    // latest 5m candle and applies a 15-minute freshness window).
    // Direct create — the existing CandleFactory writes a stale
    // `candle_time` column that no longer exists in the schema.
    Candle::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'timeframe' => '5m',
        'timestamp' => now()->timestamp,
        'candle_time_utc' => now(),
        'open' => $currentPrice,
        'high' => $currentPrice,
        'low' => $currentPrice,
        'close' => $currentPrice,
        'volume' => 0,
    ]);

    $candleIndicator = seedDashboardCandleIndicator();

    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $candleIndicator->id,
        'timeframe' => '1d',
        'timestamp' => now()->timestamp,
        'data' => [
            'open' => [$previousOpen, Kraite\Core\Support\Math::add($previousOpen, '100')],
            'close' => [Kraite\Core\Support\Math::add($previousOpen, '50'), $currentPrice],
        ],
        'conclusion' => null,
    ]);

    return Position::factory()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
    ]);
}

it('computes positive percent move when current price is above the daily open', function (): void {
    $position = makePositionWith1dOpen(token: 'DVPOSL', previousOpen: '100', currentPrice: '110');

    expect($position->daily_variation_percentage)->toBe('10.00');
});

it('computes negative percent move when current price is below the daily open', function (): void {
    $position = makePositionWith1dOpen(token: 'DVNEG', previousOpen: '200', currentPrice: '190');

    expect($position->daily_variation_percentage)->toBe('-5.00');
});

it('returns "0.00" when the dashboard candle indicator does not exist', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $symbol = Symbol::factory()->create(['token' => 'DVNOIND']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'DVNOIND',
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
    ]);

    Candle::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'timeframe' => '5m',
        'timestamp' => now()->timestamp,
        'candle_time_utc' => now(),
        'open' => '100',
        'high' => '100',
        'low' => '100',
        'close' => '100',
        'volume' => 0,
    ]);

    $position = Position::factory()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
    ]);

    expect($position->daily_variation_percentage)->toBe('0.00');
});

it('returns "0.00" when no 1d candle history row exists for the symbol', function (): void {
    seedDashboardCandleIndicator();

    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $symbol = Symbol::factory()->create(['token' => 'DVNOROW']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'DVNOROW',
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
    ]);

    Candle::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'timeframe' => '5m',
        'timestamp' => now()->timestamp,
        'candle_time_utc' => now(),
        'open' => '100',
        'high' => '100',
        'low' => '100',
        'close' => '100',
        'volume' => 0,
    ]);

    $position = Position::factory()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
    ]);

    expect($position->daily_variation_percentage)->toBe('0.00');
});

it('returns "0.00" when the previous-day open is zero (avoids divide-by-zero)', function (): void {
    $position = makePositionWith1dOpen(token: 'DVZEROOPEN', previousOpen: '0', currentPrice: '50');

    expect($position->daily_variation_percentage)->toBe('0.00');
});

it('returns "0.00" when the exchangeSymbol is missing or current_price is empty', function (): void {
    $position = Position::factory()->create([
        'exchange_symbol_id' => null,
    ]);

    expect($position->daily_variation_percentage)->toBe('0.00');
});

it('preserves precision on a small fractional move', function (): void {
    // 100.00 → 100.33 = +0.33%. Float arithmetic would also reach this,
    // but the BCMath path must not round it to 0.00 or drift to 0.32.
    $position = makePositionWith1dOpen(token: 'DVPRECISION', previousOpen: '100', currentPrice: '100.33');

    expect($position->daily_variation_percentage)->toBe('0.33');
});
