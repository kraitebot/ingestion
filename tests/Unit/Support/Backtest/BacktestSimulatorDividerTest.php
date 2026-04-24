<?php

declare(strict_types=1);

use Kraite\Core\Support\Backtest\BacktestSimulator;

/**
 * Regression cover for the 2026-04-24 backtest divider fix.
 *
 * Production trading (VerifyOrderNotionalForMarketOrderJob +
 * PlaceMarketOrderJob) sizes the market leg as
 *
 *     notional = (margin × leverage) / 2^(N+1)
 *
 * The backtest simulator shipped overnight passed `margin` straight to
 * `Kraite::calculateMarketOrderData`, which does NOT apply the divider
 * (that's the "unbounded ladder" primitive; callers apply divider
 * externally). So backtest results on low-price tokens overstated
 * ladder feasibility — min_notional rejections that fire in production
 * weren't firing in sim. This test pins the fix: the simulator source
 * must reference `get_market_order_amount_divider` on the market-sizing
 * code path.
 *
 * We keep this at the source-inspection level deliberately. A full
 * behavioural test requires a Candle fixture set + ExchangeSymbol with
 * precision columns, and the divider's effect on rebound classification
 * is indirect (it changes min_notional gating, not TP targets). The
 * source-reference guard is the cheapest, most durable catch for a
 * future refactor that silently drops the divider again.
 */
it('BacktestSimulator applies the production divider when sizing the market leg', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(BacktestSimulator::class))->getFileName()
    );

    // The production helper that returns 2^(N+1) must be in the call site.
    expect($source)->toContain('get_market_order_amount_divider($totalLimitOrders)');

    // The divided margin must reach the market-sizing call — if a future
    // edit swaps the arg back to raw $margin, this catches it.
    expect($source)->toContain('Kraite::calculateMarketOrderData($dividedMargin');
});

it('the divider helper returns 2^(N+1) so N=4 → 32', function (): void {
    // Sanity check on the production helper itself. If this formula
    // ever changes (e.g. unbounded model turned back on), the backtest
    // and the live trader need to move together.
    expect(get_market_order_amount_divider(4))->toBe(32);
    expect(get_market_order_amount_divider(0))->toBe(2);
    expect(get_market_order_amount_divider(3))->toBe(16);
});
