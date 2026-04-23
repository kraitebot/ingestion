<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\VerifyOrderNotionalForMarketOrderJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Trading\Kraite;

/**
 * Regression guard for the USELESS #64 incident: the market filled, the limit
 * ladder then failed min_notional, and the cancel workflow was forced to
 * unwind the orphaned entry at a realized loss. The fix simulates the DCA
 * ladder inside VerifyOrderNotionalForMarketOrderJob so an infeasible ladder
 * aborts the workflow before PlaceMarketOrderJob runs.
 *
 * These tests assert the contract at two levels:
 *
 *   1. The ladder calculator throws for the exact USELESS-shaped scenario, so
 *      calling it from the pre-gate produces the desired abort.
 *   2. The pre-gate method references the calculator by name, so future edits
 *      can't silently regress the call site.
 */
it('the limit ladder calculator rejects a USELESS-shaped rung before any market placement', function (): void {
    $symbol = new ExchangeSymbol;
    $symbol->parsed_trading_pair = 'USELESSUSDT';
    $symbol->min_notional = '5.00000000';
    $symbol->percentage_gap_short = '9.50';
    $symbol->percentage_gap_long = '8.50';
    $symbol->min_price = null;
    $symbol->max_price = null;
    $symbol->tick_size = '0.0000001';
    $symbol->step_size = '1';
    $symbol->price_precision = 7;
    $symbol->quantity_precision = 0;

    expect(function () use ($symbol): void {
        Kraite::calculateLimitOrdersData(
            totalLimitOrders: 4,
            direction: 'SHORT',
            referencePrice: '0.0407820',
            marketOrderQty: '415',
            exchangeSymbol: $symbol,
            limitQuantityMultipliers: [2, 2, 2, 2],
        );
    })->toThrow(RuntimeException::class, 'below min_notional');
});

it('VerifyOrderNotionalForMarketOrderJob calls the ladder calculator inside computeApiable', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(VerifyOrderNotionalForMarketOrderJob::class))->getFileName()
    );

    expect($source)->toContain('Kraite::calculateLimitOrdersData(');
    expect($source)->toContain('referencePrice: $markPrice');
    expect($source)->toContain('marketOrderQty: $marketOrderQuantity');
});
