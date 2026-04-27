<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Observers\ExchangeSymbolObserver;

/**
 * Asymmetric Binance→siblings fan-out for `percentage_gap_long` and
 * `percentage_gap_short`.
 *
 * Gap percentages drive the limit-order ladder spacing, and the value
 * is pinned by Binance backtesting data, so:
 *
 *   Binance edit  → propagate to every sibling exchange row
 *   Non-Binance edit  → DOES NOT propagate (prevents Bitget→Binance
 *                       re-propagation deadlock)
 *
 * Mirrors the shape of the per-symbol TP/SL observer
 * (`propagateTpSlOverridesToSiblings`) — same asymmetric semantics,
 * same `saveQuietly` recursion guard, same precision-safe `Math::equal`
 * idempotency check.
 */
function makeBinanceAndSiblingForGapTest(): array
{
    ExchangeSymbolObserver::resetBinanceSystemIdCache();

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $bitget = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    $symbol = Symbol::factory()->create();

    $binanceRow = ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $binance->id,
        'percentage_gap_long' => '8.50',
        'percentage_gap_short' => '9.50',
    ]);

    $bitgetRow = ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $bitget->id,
        'percentage_gap_long' => '8.50',
        'percentage_gap_short' => '9.50',
    ]);

    return [$binanceRow, $bitgetRow];
}

it('Binance gap_long edit propagates to non-Binance siblings', function (): void {
    [$binance, $bitget] = makeBinanceAndSiblingForGapTest();

    $binance->percentage_gap_long = '9.50';
    $binance->save();

    $bitget->refresh();

    expect((float) $bitget->percentage_gap_long)->toBe(9.50);
});

it('Binance gap_short edit propagates to non-Binance siblings', function (): void {
    [$binance, $bitget] = makeBinanceAndSiblingForGapTest();

    $binance->percentage_gap_short = '10.00';
    $binance->save();

    $bitget->refresh();

    expect((float) $bitget->percentage_gap_short)->toBe(10.00);
});

it('Binance combined gap edit propagates both values in one save', function (): void {
    [$binance, $bitget] = makeBinanceAndSiblingForGapTest();

    $binance->percentage_gap_long = '9.50';
    $binance->percentage_gap_short = '10.00';
    $binance->save();

    $bitget->refresh();

    expect((float) $bitget->percentage_gap_long)->toBe(9.50)
        ->and((float) $bitget->percentage_gap_short)->toBe(10.00);
});

it('Bitget gap edit does NOT propagate to Binance (asymmetric)', function (): void {
    [$binance, $bitget] = makeBinanceAndSiblingForGapTest();

    $bitget->percentage_gap_long = '12.00';
    $bitget->percentage_gap_short = '15.00';
    $bitget->save();

    $binance->refresh();

    // Binance row unchanged — only Binance is the source of truth.
    expect((float) $binance->percentage_gap_long)->toBe(8.50)
        ->and((float) $binance->percentage_gap_short)->toBe(9.50);
});

it('idempotent re-save with equal-but-differently-formatted gap decimals does not flap siblings', function (): void {
    // '9.50' and '9.5' are numerically equal — the precision-safe
    // comparator must treat them as no-op so we don't write a useless
    // row update on every save.
    [$binance, $bitget] = makeBinanceAndSiblingForGapTest();

    $binance->percentage_gap_long = '9.50';
    $binance->save();

    $bitget->refresh();
    $bitgetUpdatedAt = $bitget->updated_at;

    $binance->percentage_gap_long = '9.50';
    $binance->save();

    $bitget->refresh();

    expect((string) $bitget->updated_at)->toBe((string) $bitgetUpdatedAt);
});
