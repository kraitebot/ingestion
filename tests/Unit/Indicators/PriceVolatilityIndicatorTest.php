<?php

declare(strict_types=1);

use Kraite\Core\Indicators\Reports\PriceVolatilityIndicator;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * Pin the per-candle `volatility%` formula on PriceVolatilityIndicator.
 *
 *   volatility% = ((high - low) / close) * 100
 *
 * Stamped before the BCMath migration so any precision drift in the
 * rewrite (small fractional moves, very high-precision crypto closes)
 * shows up here, not in production indicator histories.
 */
function makeVolatilityIndicator(): PriceVolatilityIndicator
{
    $symbol = ExchangeSymbol::factory()->create([
        'token' => 'PVOL',
        'quote' => 'USDT',
    ]);

    return new PriceVolatilityIndicator($symbol, ['interval' => '1h']);
}

it('computes a 1% volatility for a 100/99/99.5 candle', function (): void {
    $indicator = makeVolatilityIndicator();

    // high - low = 1, close = 100 → 1% exactly.
    $result = $indicator->volatilityPercent([
        'high' => '100.5',
        'low' => '99.5',
        'close' => '100',
    ]);

    expect((float) $result)->toEqualWithDelta(1.0, 0.0000001);
});

it('returns null on a zero or negative close', function (): void {
    $indicator = makeVolatilityIndicator();

    expect($indicator->volatilityPercent([
        'high' => '100',
        'low' => '99',
        'close' => '0',
    ]))->toBeNull();

    expect($indicator->volatilityPercent([
        'high' => '100',
        'low' => '99',
        'close' => '-1',
    ]))->toBeNull();
});

it('returns null when any field is missing or non-numeric', function (): void {
    $indicator = makeVolatilityIndicator();

    expect($indicator->volatilityPercent(['high' => '100', 'low' => '99']))->toBeNull();
    expect($indicator->volatilityPercent([
        'high' => 'not-a-number',
        'low' => '99',
        'close' => '100',
    ]))->toBeNull();
});

it('preserves precision on a small fractional move', function (): void {
    $indicator = makeVolatilityIndicator();

    // high - low = 0.0033, close = 100.33 → ~0.003290% — float OK,
    // BCMath path must not collapse this to 0 or drift wildly.
    $result = $indicator->volatilityPercent([
        'high' => '100.3333',
        'low' => '100.33',
        'close' => '100.33',
    ]);

    expect((float) $result)->toBeGreaterThan(0.0)->toBeLessThan(0.01);
});

it('handles high-precision crypto-scale numbers without losing accuracy', function (): void {
    $indicator = makeVolatilityIndicator();

    // BTC-style values; float can represent these but BCMath should
    // match within a tight tolerance on the rewrite.
    $result = $indicator->volatilityPercent([
        'high' => '67500.12345678',
        'low' => '66800.87654321',
        'close' => '67100.00000000',
    ]);

    $expected = ((67500.12345678 - 66800.87654321) / 67100.00000000) * 100.0;
    expect((float) $result)->toEqualWithDelta($expected, 0.0001);
});
