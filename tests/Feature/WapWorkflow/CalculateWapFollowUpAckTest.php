<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * CalculateWap::complete() is the exit point for a successful WAP run.
 * Alongside bumping reference_* on the profit order, it resolves the
 * "sequential LIMIT fills arrived during the prior WAP" case: any LIMIT
 * that filled while this WAP was running (and was therefore skipped by
 * observer dedup) is claimed here — reference_status bulk-bumped to FILLED
 * and a follow-up ApplyWapJob step enqueued (+3s delay) so the new fill's
 * qty is folded into the TP on the next breakeven snapshot.
 */
function makeWapScenarioPosition(): Position
{
    return Position::factory()->long()->create([
        'status' => 'waping',
        'profit_percentage' => 0.35,
        'parsed_trading_pair' => 'BTCUSDT',
        // High ceiling so the observer's enforceOrderLimits doesn't reject
        // the LIMIT rows this suite seeds (default is null → ceiling of 0).
        'total_limit_orders' => 10,
    ]);
}

function makeProfitOrderFor(Position $position): Order
{
    return Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'type' => 'PROFIT-LIMIT',
        'price' => '40500.00',
        'quantity' => '0.003',
        'position_side' => $position->direction,
        'status' => 'NEW',
    ]);
}

function makeFilledLimit(Position $position, ?string $referenceStatus = null): Order
{
    return Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '39500.00',
        'quantity' => '0.001',
        'position_side' => $position->direction,
        'status' => 'FILLED',
        'reference_status' => $referenceStatus,
    ]);
}

function runCompleteFor(Position $position, Order $profitOrder): void
{
    $job = new CalculateWapAndModifyProfitOrderJob(positionId: $position->id);
    // Normally populated by startOrFail + computeApiable; for a pure unit
    // exercise of complete() we wire the dependency directly.
    $job->profitOrder = $profitOrder;

    $job->complete();
}

it('bumps reference_* on the profit order after a successful WAP', function () {
    $position = makeWapScenarioPosition();
    $profitOrder = makeProfitOrderFor($position);

    runCompleteFor($position, $profitOrder);

    // price/quantity accessors strip trailing zeros; reference_* is the raw
    // decimal column. Compare numerically so formatting doesn't mislead.
    $fresh = $profitOrder->fresh();
    expect((float) $fresh->reference_price)->toBe((float) $fresh->price)
        ->and((float) $fresh->reference_quantity)->toBe((float) $fresh->quantity);
});

it('flags the position as waped and mirrors profit order qty onto it', function () {
    $position = makeWapScenarioPosition();
    $profitOrder = makeProfitOrderFor($position);

    runCompleteFor($position, $profitOrder);

    $fresh = $position->fresh();
    expect((bool) $fresh->was_waped)->toBeTrue()
        ->and($fresh->waped_at)->not->toBeNull()
        ->and((float) $fresh->quantity)->toBe((float) $profitOrder->fresh()->quantity);
});

it('does nothing extra when every filled LIMIT is already acked', function () {
    $position = makeWapScenarioPosition();
    $profitOrder = makeProfitOrderFor($position);
    makeFilledLimit($position, referenceStatus: 'FILLED');

    runCompleteFor($position, $profitOrder);

    // Follow-up WAP steps land in the `trading_steps` prefix as of
    // 2026-05-13 review-15 Finding 6 (explicit Steps::usingPrefix wrap).
    expect(Steps::usingPrefix('trading', fn () => Step::where('class', ApplyWapJob::class)->count()))->toBe(0);
});

it('claims unacked filled LIMITs and enqueues a follow-up WAP', function () {
    $position = makeWapScenarioPosition();
    $profitOrder = makeProfitOrderFor($position);

    // Two LIMITs filled while the current WAP was running — observer dedup
    // skipped them, so their reference_status never moved to FILLED.
    $limitA = makeFilledLimit($position, referenceStatus: null);
    $limitB = makeFilledLimit($position, referenceStatus: 'NEW');

    runCompleteFor($position, $profitOrder);

    // Both LIMITs must be claimed atomically so the next sync-orders tick
    // doesn't re-dispatch a competing WAP on top of ours.
    expect($limitA->fresh()->reference_status)->toBe('FILLED')
        ->and($limitB->fresh()->reference_status)->toBe('FILLED');

    $followUp = Steps::usingPrefix('trading', fn () => Step::where('class', ApplyWapJob::class)->first());

    expect($followUp)->not->toBeNull()
        ->and($followUp->arguments['positionId'])->toBe($position->id)
        ->and($followUp->dispatch_after)->not->toBeNull()
        ->and($followUp->dispatch_after->greaterThan(now()))->toBeTrue();
});

it('only claims LIMIT rows — ignores unacked non-LIMIT orders', function () {
    $position = makeWapScenarioPosition();
    $profitOrder = makeProfitOrderFor($position);

    // A FILLED MARKET order without ack must not be dragged into the claim:
    // the market entry is not a DCA signal and has no bearing on WAP.
    $market = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'type' => 'MARKET',
        'price' => '40000.00',
        'quantity' => '0.001',
        'position_side' => $position->direction,
        'status' => 'FILLED',
        'reference_status' => null,
    ]);

    runCompleteFor($position, $profitOrder);

    expect($market->fresh()->reference_status)->toBeNull()
        ->and(Steps::usingPrefix('trading', fn () => Step::where('class', ApplyWapJob::class)->count()))->toBe(0);
});
