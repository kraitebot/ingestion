<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Symbol;

/**
 * 2026-05-04 — Pin the mark-price split into the
 * `exchange_symbol_prices` sidecar.
 *
 * Why the split: the price daemon's bulk UPDATE on every WS tick
 * (1Hz cadence, ~500 rows per batch) was holding row locks on
 * `exchange_symbols` long enough to wedge the indicator
 * pipeline / direction-burst at :30 / api_request_logs INSERTs
 * — manifesting as recurring slow_query alerts (197s lock-wait
 * incidents on 2026-05-03 14:37 + 2026-05-04 00:10). Memory
 * ref: db_lock_contention_mark_price_daemon.md.
 *
 * Cutover contract:
 *   - The sidecar is the single source of truth for writes.
 *   - `ExchangeSymbol::$mark_price` accessor proxies reads
 *     through the relationship; falls back to the legacy
 *     column on the parent table during the soak window.
 *   - `mark_price_synced_at` follows the same accessor pattern.
 *   - The daemon writes only to the sidecar; the parent
 *     columns no longer receive 1Hz updates.
 */
function makeSidecarSymbol(string $token = 'SDC', ?string $sidecarPrice = null, ?string $legacyPrice = null): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'mark_price' => $legacyPrice,
    ]);

    if ($sidecarPrice !== null) {
        ExchangeSymbolPrice::updateOrCreate(
            ['exchange_symbol_id' => $exchangeSymbol->id],
            ['mark_price' => $sidecarPrice, 'mark_price_synced_at' => now()],
        );
    } else {
        // Factory's afterCreating may have populated the sidecar
        // with the legacy mirror; clear it so the test's "sidecar
        // is null" branch can be exercised cleanly.
        ExchangeSymbolPrice::where('exchange_symbol_id', $exchangeSymbol->id)->delete();
    }

    // Reload so the accessor's relationship cache is fresh.
    return ExchangeSymbol::find($exchangeSymbol->id);
}

it('reads mark_price from the sidecar when the sidecar row is present', function (): void {
    $exchangeSymbol = makeSidecarSymbol(
        token: 'SDC1',
        sidecarPrice: '12.34000000',
        legacyPrice: '99.99000000',  // legacy column is stale
    );

    expect($exchangeSymbol->mark_price)->toBe(
        '12.34000000',
        'When the sidecar row carries a mark_price, the accessor must return THAT value — not the stale legacy column.'
    );
});

it('falls back to the legacy column when the sidecar row is missing', function (): void {
    $exchangeSymbol = makeSidecarSymbol(
        token: 'SDC2',
        sidecarPrice: null,
        legacyPrice: '7.77000000',
    );

    expect($exchangeSymbol->mark_price)->toBe(
        '7.77000000',
        'During the cutover-soak window, symbols whose sidecar row hasn\'t landed yet must still read from the legacy column.'
    );
});

it('returns null when neither the sidecar nor the legacy column has a value', function (): void {
    $exchangeSymbol = makeSidecarSymbol(
        token: 'SDC3',
        sidecarPrice: null,
        legacyPrice: null,
    );

    expect($exchangeSymbol->mark_price)->toBeNull();
});

it('mark_price_synced_at follows the same sidecar-first pattern', function (): void {
    $exchangeSymbol = makeSidecarSymbol(
        token: 'SDC4',
        sidecarPrice: '5.50000000',
        legacyPrice: null,
    );

    // Update the sidecar's synced_at to a known value.
    ExchangeSymbolPrice::where('exchange_symbol_id', $exchangeSymbol->id)
        ->update(['mark_price_synced_at' => '2026-05-04 12:00:00']);

    $fresh = ExchangeSymbol::find($exchangeSymbol->id);

    expect($fresh->mark_price_synced_at)->not->toBeNull();
    expect($fresh->mark_price_synced_at->format('Y-m-d H:i:s'))->toBe('2026-05-04 12:00:00');
});

it('the daemon write target is exchange_symbol_prices, not exchange_symbols', function (): void {
    // Source-level pin: future refactors that flip the daemon's
    // write target back to `exchange_symbols` re-introduce the
    // 192s lock-wait class. Pin the SQL string explicitly so
    // any such regression fails this test loud.
    $source = file_get_contents(
        (new ReflectionClass(Kraite\Core\Commands\Daemons\StreamBinancePricesCommand::class))->getFileName()
    );

    expect($source)->toContain('UPDATE exchange_symbol_prices');
    expect($source)->not->toContain('UPDATE exchange_symbols SET mark_price');
});

it('the priceRow relationship is wired on ExchangeSymbol', function (): void {
    $exchangeSymbol = makeSidecarSymbol(
        token: 'SDC5',
        sidecarPrice: '1.23000000',
        legacyPrice: null,
    );

    expect($exchangeSymbol->priceRow)->toBeInstanceOf(ExchangeSymbolPrice::class);
    expect($exchangeSymbol->priceRow->mark_price)->toBe('1.23000000');
});

it('the freshness check (CheckSystemHealthCommand #0) reads from the sidecar', function (): void {
    // Source-level pin: the freshness watchdog filters on
    // mark_price_synced_at at the SQL level. Post-cutover it
    // MUST join the sidecar — not the legacy column on
    // exchange_symbols (which after the soak migration won't
    // receive fresh writes any more).
    $source = file_get_contents(
        (new ReflectionClass(Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect($source)->toContain('exchange_symbol_prices.mark_price_synced_at');
    expect($source)->toContain('exchange_symbol_prices.exchange_symbol_id');
});
