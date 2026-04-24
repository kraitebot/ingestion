<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\TradingMappers\BinanceTradingMapper;
use Kraite\Core\Support\TradingMappers\BitgetTradingMapper;
use Kraite\Core\Support\TradingMappers\BybitTradingMapper;
use Kraite\Core\Support\TradingMappers\KucoinTradingMapper;

/**
 * Regression cover for the 2026-04-24 delisting-flag persistence bug.
 *
 * When Binance changed `delivery_ts_ms` from the perpetual sentinel
 * (4133404800000 ≈ Dec 2100) to real settlement dates for 6 tokens
 * (DEGEN, B3, BOB, ZKJ, DAM, IR), the pushover notifications fired
 * correctly — but `is_marked_for_delisting` was NEVER persisted on any
 * of the 6 rows.
 *
 * Root cause: `isNowDelisted()` in every TradingMapper uses
 * `$model->wasChanged('delivery_ts_ms')`. `wasChanged()` returns true
 * ONLY after the save commits. So when the ExchangeSymbolObserver
 * `saving()` hook calls `isBeingDelisted()` (which calls
 * `isNowDelisted()`), the method returns false — because the save
 * hasn't committed yet — and the flag-flip path
 * (`$model->is_marked_for_delisting = true` on line 193 of the
 * observer) never executes. Later in the `saved()` hook, the
 * notification fires (then `wasChanged` = true), but by then the row
 * is already persisted without the flag.
 *
 * These tests pin the contract at two levels:
 *
 *   1. Unit: `isNowDelisted()` must return true when the model is in
 *      the pre-save state with a dirty delivery_ts_ms change —
 *      `saving()` hook context. Currently it returns false (bug).
 *
 *   2. Architectural: all four mappers (Binance / Bybit / KuCoin /
 *      Bitget) must use the same detection method so the fix stays
 *      consistent across exchanges — the bug is identical in all
 *      four.
 */
function exchangeSymbolWithDirtyDeliveryTsMs(int $previous, int $next): ExchangeSymbol
{
    $symbol = new ExchangeSymbol;

    // Mimic a model freshly hydrated from DB (previous value is in
    // original, exists set to true) with a pending unsaved change to
    // delivery_ts_ms. This is exactly the state inside the `saving`
    // observer hook.
    $symbol->exists = true;
    $symbol->setRawAttributes(['delivery_ts_ms' => $previous], sync: true);
    $symbol->delivery_ts_ms = $next;

    return $symbol;
}

it('Binance isNowDelisted returns true in pre-save (saving hook) context when delivery_ts_ms moves off perpetual default', function (): void {
    $symbol = exchangeSymbolWithDirtyDeliveryTsMs(
        previous: BinanceTradingMapper::PERPETUAL_DEFAULT,
        next: 1777366800000, // 2026-04-28 11:00 UTC — real delisting
    );

    expect($symbol->isDirty('delivery_ts_ms'))->toBeTrue();
    expect($symbol->wasChanged('delivery_ts_ms'))->toBeFalse();

    // This is the assertion that exposes the bug: in the saving hook,
    // the method must still recognise the dirty change as a delisting
    // event, otherwise the is_marked_for_delisting flag never gets
    // set before the write commits.
    expect((new BinanceTradingMapper)->isNowDelisted($symbol))->toBeTrue();
});

it('Binance isNowDelisted still returns true in post-save (saved hook) context', function (): void {
    // Simulate the saved() hook — change has committed, wasChanged returns true.
    $symbol = new ExchangeSymbol;
    $symbol->exists = true;
    $symbol->setRawAttributes(['delivery_ts_ms' => 1777366800000], sync: true);
    $symbol->syncChanges();
    // Manually populate changes so wasChanged returns true as it would post-save.
    $refl = new ReflectionClass($symbol);
    $changesProp = $refl->getProperty('changes');
    $changesProp->setAccessible(true);
    $changesProp->setValue($symbol, ['delivery_ts_ms' => 1777366800000]);
    $originalProp = $refl->getProperty('original');
    $originalProp->setAccessible(true);
    $originalProp->setValue($symbol, ['delivery_ts_ms' => BinanceTradingMapper::PERPETUAL_DEFAULT]);

    expect($symbol->wasChanged('delivery_ts_ms'))->toBeTrue();
    expect((new BinanceTradingMapper)->isNowDelisted($symbol))->toBeTrue();
});

it('Binance isNowDelisted returns false when the perpetual default is itself the new value', function (): void {
    $symbol = exchangeSymbolWithDirtyDeliveryTsMs(
        previous: 0,
        next: BinanceTradingMapper::PERPETUAL_DEFAULT,
    );

    // Perpetual default IS the normal state for a USDM perp — this is
    // NOT a delisting, the fix must not false-positive it.
    expect((new BinanceTradingMapper)->isNowDelisted($symbol))->toBeFalse();
});

it('Binance isNowDelisted returns false when delivery_ts_ms is unchanged', function (): void {
    $symbol = new ExchangeSymbol;
    $symbol->exists = true;
    $symbol->setRawAttributes(['delivery_ts_ms' => 1777366800000], sync: true);
    // No change made — model is clean.

    expect($symbol->isDirty('delivery_ts_ms'))->toBeFalse();
    expect($symbol->wasChanged('delivery_ts_ms'))->toBeFalse();

    expect((new BinanceTradingMapper)->isNowDelisted($symbol))->toBeFalse();
});

it('Bybit isNowDelisted recognises a dirty delisting change in the saving hook context', function (): void {
    $symbol = exchangeSymbolWithDirtyDeliveryTsMs(
        previous: BinanceTradingMapper::PERPETUAL_DEFAULT,
        next: 1777366800000,
    );

    expect((new BybitTradingMapper)->isNowDelisted($symbol))->toBeTrue();
});

it('KuCoin isNowDelisted recognises a dirty delisting change in the saving hook context', function (): void {
    $symbol = exchangeSymbolWithDirtyDeliveryTsMs(
        previous: BinanceTradingMapper::PERPETUAL_DEFAULT,
        next: 1777366800000,
    );

    expect((new KucoinTradingMapper)->isNowDelisted($symbol))->toBeTrue();
});

it('Bitget isNowDelisted recognises a dirty delisting change in the saving hook context', function (): void {
    $symbol = exchangeSymbolWithDirtyDeliveryTsMs(
        previous: BinanceTradingMapper::PERPETUAL_DEFAULT,
        next: 1777366800000,
    );

    expect((new BitgetTradingMapper)->isNowDelisted($symbol))->toBeTrue();
});

it('all four mappers must check dirty OR wasChanged consistently in source', function (): void {
    // Belt-and-suspenders source-level guard: a future edit that drops
    // the isDirty branch in ONE mapper would silently re-introduce the
    // bug on that exchange. Assert the detection pattern is present in
    // every file.
    $files = [
        (new ReflectionClass(BinanceTradingMapper::class))->getFileName(),
        (new ReflectionClass(BybitTradingMapper::class))->getFileName(),
        (new ReflectionClass(KucoinTradingMapper::class))->getFileName(),
        (new ReflectionClass(BitgetTradingMapper::class))->getFileName(),
    ];

    foreach ($files as $path) {
        $source = file_get_contents($path);
        // Both signals must appear — `isDirty` covers the saving-hook
        // call, `wasChanged` covers the saved-hook call. Dropping either
        // regresses one of the observer invocations of the method.
        expect($source)->toContain("isDirty('delivery_ts_ms')");
        expect($source)->toContain("wasChanged('delivery_ts_ms')");
    }
});
