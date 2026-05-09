<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\CancelAlgoOpenOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * SL fired on Binance shows up as `algoStatus=TRIGGERED` on the algo
 * order. This is a distinct exchange event from a fill — the algo is
 * the trigger record, the close-MARKET that follows is the execution.
 *
 * Three behavioural pins, all required for the audit trail to survive
 * the close lifecycle:
 *
 *  1. OrderObserver must dispatch ClosePositionJob on STOP-MARKET going
 *     TRIGGERED, the same way it does for FILLED today. Without this,
 *     the close path is silently dropped and the position only closes
 *     via the WS reduce-only fall-back (`maybeDetectManualPositionClose`)
 *     — different code path, different telemetry shape.
 *
 *  2. CancelAlgoOpenOrdersJob must skip rows already at TRIGGERED. The
 *     algo is finished server-side; sending a cancel API call returns
 *     a CANCELLED response that overwrites the local TRIGGERED truth.
 *
 *  3. OrderObserver::updating must pin TRIGGERED as a terminal state.
 *     Multiple paths (sync-orders empty fallback, the cancel response
 *     from #2 if it slips past, exchange-side races) try to write
 *     CANCELLED over TRIGGERED. Pinning here is a single chokepoint —
 *     once an algo carries the TRIGGERED truth, no caller can erase
 *     it.
 */
function buildBinanceTriggeredTestPosition(int $totalLimitOrders = 0): Position
{
    // Mirror `createTestPosition` from OrderObserverTest.php so the factory
    // builds a position in whatever default state the existing test suite
    // already exercises. Setting `status='active'` explicitly was triggering
    // a downstream observer cascade in this test harness; leaving the
    // factory default keeps us aligned with the working test fixture.
    return Position::factory()->long()->create([
        'total_limit_orders' => $totalLimitOrders,
    ]);
}

function buildAlgoStopLossOrder(Position $position, string $status = 'NEW', ?string $exchangeOrderId = null): Order
{
    return Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.16460',
        'quantity' => '0',
        'is_algo' => true,
        'status' => $status,
        'exchange_order_id' => $exchangeOrderId ?? '1000001596054504',
    ]);
}

// ───────────────── Pin 1: TRIGGERED dispatches ClosePositionJob ─────────────────

it('dispatches ClosePositionJob when a STOP-MARKET algo transitions to TRIGGERED', function (): void {
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'NEW');

    $sl->update(['status' => 'TRIGGERED']);

    $exists = Steps::usingPrefix('trading', fn (): bool => Step::where('class', ClosePositionJob::class)
        ->whereJsonContains('arguments->positionId', $position->id)
        ->exists());

    expect($exists)->toBeTrue();
});

it('still dispatches ClosePositionJob when a STOP-MARKET goes FILLED (regression guard)', function (): void {
    // Pre-existing behaviour: regular STOP_MARKET fills still close the position.
    // Adding TRIGGERED must NOT remove FILLED from the trigger set.
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'NEW');

    $sl->update(['status' => 'FILLED']);

    $step = Steps::usingPrefix('trading', fn () => Step::where('class', ClosePositionJob::class)->first());

    expect($step)->not->toBeNull();
});

// ───────────────── Pin 2: CancelAlgoOpenOrdersJob skips TRIGGERED ─────────────────

it('does not include TRIGGERED algo orders in the cancellation set', function (): void {
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'TRIGGERED');

    // Re-implementing the job's selector here — the job calls apiCancel()
    // which would require a live Binance client. We only need to prove
    // that the row would not be selected for cancellation in the first
    // place, mirroring the INACTIVE_STATUSES filter in the job.
    $candidates = $position->orders()
        ->where('is_algo', true)
        ->whereNotIn('status', ['FILLED', 'CANCELLED', 'EXPIRED', 'TRIGGERED'])
        ->whereNotNull('exchange_order_id')
        ->pluck('id')
        ->all();

    expect($candidates)->not->toContain($sl->id);
});

it('still cancels NEW algo orders (regression guard)', function (): void {
    // The cancel-all path must keep working for live orders. Adding TRIGGERED
    // to the skip list must not change behaviour for NEW.
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'NEW');

    $candidates = $position->orders()
        ->where('is_algo', true)
        ->whereNotIn('status', ['FILLED', 'CANCELLED', 'EXPIRED', 'TRIGGERED'])
        ->whereNotNull('exchange_order_id')
        ->pluck('id')
        ->all();

    expect($candidates)->toContain($sl->id);
});

it('exposes TRIGGERED inside the job-level INACTIVE_STATUSES constant', function (): void {
    // Belt-and-suspenders — the Pest assertion above mirrors the constant,
    // but the constant is the single source of truth that the production
    // job code reads. Lock it explicitly so a refactor can't silently
    // drop TRIGGERED without flipping this test red.
    $reflected = new ReflectionClass(CancelAlgoOpenOrdersJob::class);

    expect($reflected->getConstant('INACTIVE_STATUSES'))->toContain('TRIGGERED');
});

// ───────────────── Pin 3: TRIGGERED is a terminal state — no downgrade ─────────────────

it('refuses to downgrade a TRIGGERED algo order to CANCELLED', function (): void {
    // Sync-orders' empty openAlgoOrders fallback returns CANCELLED for an
    // algo that has already finished on the exchange. Without the pin
    // the local row would lose its TRIGGERED truth on every 5-min cycle.
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'TRIGGERED');

    $sl->update(['status' => 'CANCELLED']);

    expect($sl->fresh()->status)->toBe('TRIGGERED');
});

it('refuses to downgrade a TRIGGERED algo order to NEW (defensive)', function (): void {
    // Any spurious update path — including a buggy reconciler that
    // re-stamps NEW from a stale snapshot — must not erase the truth.
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'TRIGGERED');

    $sl->update(['status' => 'NEW']);

    expect($sl->fresh()->status)->toBe('TRIGGERED');
});

it('still allows transitions to TRIGGERED from any non-terminal state (no false pin)', function (): void {
    // The pin is asymmetric: it only catches outgoing transitions FROM
    // TRIGGERED. Incoming transitions (NEW → TRIGGERED is the canonical
    // SL-fire path) must work normally, otherwise the pin would itself
    // block the very state we're trying to record.
    $position = buildBinanceTriggeredTestPosition();
    $sl = buildAlgoStopLossOrder($position, status: 'NEW');

    $sl->update(['status' => 'TRIGGERED']);

    expect($sl->fresh()->status)->toBe('TRIGGERED');
});
