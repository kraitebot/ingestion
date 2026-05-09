<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\Bitget\PlacePositionTpslJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceStopLossOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

/**
 * Simple-trade mode (total_limit_orders = 0) must still place a stop-loss.
 *
 * Historic shape coupled SL placement to the deepest LIMIT rung's scheduled
 * price (martingale's worst-case projected fill). With no ladder, that anchor
 * never exists — the gate would refuse and the position would run unhedged.
 *
 * Resolution rule (independent for Binance + Bitget jobs):
 *
 *   total_limit_orders === 0  → anchor is the MARKET fill price (`opening_price`)
 *   total_limit_orders >= 1   → anchor is `lastLimitOrder()->price` (unchanged)
 *
 * `opening_price` is the canonical "entry fill" field (PlaceMarketOrderJob
 * writes it from the filled MARKET; same field DispatchLimitOrdersJob and
 * PlaceProfitOrderJob already gate on), so simple-trade mode reuses the same
 * source of truth without introducing a parallel anchor concept.
 *
 * Reflection-built instances exercise the public anchor + startOrFail logic
 * without the parent BaseApiableJob boot ceremony, mirroring the pattern
 * established by `PlaceStopLossOrderFallbackTest`.
 */
function newBinanceSlJob(): PlaceStopLossOrderJob
{
    return (new ReflectionClass(PlaceStopLossOrderJob::class))
        ->newInstanceWithoutConstructor();
}

function newBitgetSlJob(): PlacePositionTpslJob
{
    return (new ReflectionClass(PlacePositionTpslJob::class))
        ->newInstanceWithoutConstructor();
}

/**
 * Build an N=0 position stub. For simple-trade tests we never reach
 * `lastLimitOrder()`, so no DB / Order rows are needed.
 */
function makeZeroLimitPosition(
    ?string $openingPrice = '0.16490',
    ?string $stopPercent = '0.15',
    ?string $quantity = '2857',
    string $status = 'opening',
    ?string $profitPercent = '5.000',
): Position {
    $exchangeSymbol = new ExchangeSymbol;

    $account = new Account;
    $account->stop_market_initial_percentage = '0.15';
    $account->override_sl = false;

    $position = new Position;
    $position->total_limit_orders = 0;
    $position->opening_price = $openingPrice;
    $position->stop_market_percentage = $stopPercent;
    $position->profit_percentage = $profitPercent;
    $position->quantity = $quantity;
    $position->status = $status;
    $position->setRelation('exchangeSymbol', $exchangeSymbol);
    $position->setRelation('account', $account);

    return $position;
}

// ───────────────────────────── Binance — anchor resolution ─────────────────────────────

it('Binance: resolves anchor to opening_price when total_limit_orders is 0', function (): void {
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: '0.16490');

    expect($job->resolveAnchorPrice())->toBe('0.16490');
});

it('Binance: returns null anchor when N=0 and opening_price is missing (MARKET not yet filled)', function (): void {
    // Fail-fast guard so startOrFail can refuse before computeApiable derefs null.
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: null);

    expect($job->resolveAnchorPrice())->toBeNull();
});

it('Binance: returns null anchor when N=0 and opening_price is empty string (defensive)', function (): void {
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: '');

    expect($job->resolveAnchorPrice())->toBeNull();
});

// ───────────────────────────── Binance — startOrFail gate ─────────────────────────────

it('Binance: startOrFail allows N=0 with opening_price + valid status + quantity + SL%', function (): void {
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition();

    expect($job->startOrFail())->toBeTrue();
});

it('Binance: startOrFail rejects N=0 when opening_price is null (no anchor)', function (): void {
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: null);

    expect($job->startOrFail())->toBeFalse();
});

it('Binance: startOrFail rejects N=0 when status is non-active (closed/cancelled/failed)', function (): void {
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition(status: 'closed');

    expect($job->startOrFail())->toBeFalse();
});

it('Binance: startOrFail rejects N=0 when quantity is null (MARKET fill not propagated yet)', function (): void {
    $job = newBinanceSlJob();
    $job->position = makeZeroLimitPosition(quantity: null);

    expect($job->startOrFail())->toBeFalse();
});

it('Binance: startOrFail rejects N=0 when SL percentage cannot be resolved', function (): void {
    $job = newBinanceSlJob();
    $position = makeZeroLimitPosition(stopPercent: null);
    $position->account->stop_market_initial_percentage = null;
    $job->position = $position;

    expect($job->startOrFail())->toBeFalse();
});

// ───────────────────────────── Bitget — anchor resolution ─────────────────────────────

it('Bitget: resolves anchor to opening_price when total_limit_orders is 0', function (): void {
    $job = newBitgetSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: '0.16490');

    expect($job->resolveAnchorPrice())->toBe('0.16490');
});

it('Bitget: returns null anchor when N=0 and opening_price is missing', function (): void {
    $job = newBitgetSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: null);

    expect($job->resolveAnchorPrice())->toBeNull();
});

// ───────────────────────────── Bitget — startOrFail gate ─────────────────────────────

it('Bitget: startOrFail allows N=0 with opening_price + valid status + quantity + profit% + SL%', function (): void {
    $job = newBitgetSlJob();
    $job->position = makeZeroLimitPosition();

    expect($job->startOrFail())->toBeTrue();
});

it('Bitget: startOrFail rejects N=0 when opening_price is null (no anchor)', function (): void {
    $job = newBitgetSlJob();
    $job->position = makeZeroLimitPosition(openingPrice: null);

    expect($job->startOrFail())->toBeFalse();
});

it('Bitget: startOrFail rejects N=0 when profit_percentage is null (TP cannot compute)', function (): void {
    $job = newBitgetSlJob();
    $job->position = makeZeroLimitPosition(profitPercent: null);

    expect($job->startOrFail())->toBeFalse();
});
