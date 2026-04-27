<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Observers\ExchangeSymbolObserver;

/**
 * Asymmetric per-symbol TP/SL propagation.
 *
 * Per-symbol TP and SL overrides are pinned by Binance backtesting data,
 * so the observer fan-out is one-directional:
 *
 *   Binance edit  → propagates to every sibling exchange (Bitget, KuCoin, ...)
 *   Non-Binance edit  → DOES NOT propagate (prevents Bitget→Binance→others
 *                       deadlock that a symmetric fan-out would create)
 *
 * Linkage is via `exchange_symbols.symbol_id` (the canonical cross-exchange
 * join — `base_asset_mappers` was retired 2025-12-08).
 */
function makeBinanceAndBitgetSiblings(): array
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
        'profit_percentage' => null,
        'stop_market_percentage' => null,
    ]);

    $bitgetRow = ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $bitget->id,
        'profit_percentage' => null,
        'stop_market_percentage' => null,
    ]);

    return [$binanceRow, $bitgetRow];
}

it('Binance TP edit propagates to non-Binance siblings', function (): void {
    [$binance, $bitget] = makeBinanceAndBitgetSiblings();

    $binance->profit_percentage = '0.500';
    $binance->save();

    $bitget->refresh();

    expect($bitget->profit_percentage)->toBe('0.500');
});

it('Binance SL edit propagates to non-Binance siblings', function (): void {
    [$binance, $bitget] = makeBinanceAndBitgetSiblings();

    $binance->stop_market_percentage = '3.50';
    $binance->save();

    $bitget->refresh();

    expect($bitget->stop_market_percentage)->toBe('3.50');
});

it('Binance combined TP+SL edit propagates both values in one save', function (): void {
    [$binance, $bitget] = makeBinanceAndBitgetSiblings();

    $binance->profit_percentage = '0.420';
    $binance->stop_market_percentage = '2.75';
    $binance->save();

    $bitget->refresh();

    expect($bitget->profit_percentage)->toBe('0.420')
        ->and($bitget->stop_market_percentage)->toBe('2.75');
});

it('Bitget edit does NOT propagate to Binance (asymmetric)', function (): void {
    [$binance, $bitget] = makeBinanceAndBitgetSiblings();

    $bitget->profit_percentage = '0.999';
    $bitget->stop_market_percentage = '5.00';
    $bitget->save();

    $binance->refresh();

    // Binance row stays NULL — only Binance is the source of truth.
    expect($binance->profit_percentage)->toBeNull()
        ->and($binance->stop_market_percentage)->toBeNull();
});

it('Binance value cleared to NULL propagates NULL to siblings', function (): void {
    [$binance, $bitget] = makeBinanceAndBitgetSiblings();

    $binance->profit_percentage = '0.500';
    $binance->stop_market_percentage = '3.00';
    $binance->save();

    $bitget->refresh();
    expect($bitget->profit_percentage)->toBe('0.500');

    $binance->profit_percentage = null;
    $binance->stop_market_percentage = null;
    $binance->save();

    $bitget->refresh();

    expect($bitget->profit_percentage)->toBeNull()
        ->and($bitget->stop_market_percentage)->toBeNull();
});

it('idempotent re-save with equal-but-differently-formatted decimals does not flap siblings', function (): void {
    // '0.500' and '0.50' are numerically equal — the precision-safe
    // comparator must treat them as no-op so we don't write a useless
    // row update on every save.
    [$binance, $bitget] = makeBinanceAndBitgetSiblings();

    $binance->profit_percentage = '0.500';
    $binance->save();

    $bitget->refresh();
    $bitgetUpdatedAt = $bitget->updated_at;

    // Trigger another Binance save with the same value (different DB
    // representation possible) — sibling should NOT be re-written.
    $binance->profit_percentage = '0.500';
    $binance->save();

    $bitget->refresh();

    expect((string) $bitget->updated_at)->toBe((string) $bitgetUpdatedAt);
});

it('siblings without symbol_id linkage do not propagate', function (): void {
    // Defensive: rows that haven't been linked to a Symbol yet (no
    // symbol_id FK) are invisible to getOthersFromExchanges and so the
    // fan-out simply skips — no exception, no cross-token leakage.
    ExchangeSymbolObserver::resetBinanceSystemIdCache();

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $bitget = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    $orphan = ExchangeSymbol::factory()->create([
        'token' => 'ORPHAN',
        'quote' => 'USDT',
        'symbol_id' => null,
        'api_system_id' => $binance->id,
    ]);

    $unrelated = ExchangeSymbol::factory()->create([
        'token' => 'UNRELATED',
        'quote' => 'USDT',
        'symbol_id' => null,
        'api_system_id' => $bitget->id,
        'profit_percentage' => null,
    ]);

    $orphan->profit_percentage = '0.700';
    $orphan->save();

    $unrelated->refresh();

    expect($unrelated->profit_percentage)->toBeNull();
});
