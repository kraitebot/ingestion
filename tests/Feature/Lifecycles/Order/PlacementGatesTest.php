<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\DispatchLimitOrdersJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceMarketOrderJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceStopLossOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the precondition gates that every order-placement atomic step
 * checks before reaching the exchange. These guards are the first line
 * of defence against placing duplicate / orphan / mis-priced orders on
 * real money accounts — a regression here ships as a phantom MARKET
 * fill or a never-anchored TP.
 *
 * Each `startOrFail()` is exercised against the canonical happy path
 * plus every guard branch the production code carries today, so
 * later edits that drop a check (e.g. removing the opening_price gate
 * on PlaceProfitOrderJob) flip these red instead of slipping into a
 * production tick where the symptom is "TP placed at price=0".
 */

// ───────────────────────── PlaceMarketOrderJob::startOrFail ─────────────────────────

it('PlaceMarketOrder: passes when position is opening with margin set and no existing MARKET order', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'margin' => '50.00',
    ]);

    $job = new PlaceMarketOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue();
});

it('PlaceMarketOrder: refuses when position is not in opening status', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'margin' => '50.00',
    ]);

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceMarketOrder: refuses when margin is missing (PrepareData has not run yet)', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'margin' => null,
    ]);

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceMarketOrder: rehydrates an existing MARKET row on retry instead of creating a duplicate', function (): void {
    // The retry guard is the whole point of this test: without it, a
    // worker crashed after Order::create but before apiPlace would on
    // retry create a SECOND market row and place a SECOND MARKET on the
    // exchange — doubling the position size silently.
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'margin' => '50.00',
    ]);

    $existingMarket = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'price' => '0.1',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    $job = new PlaceMarketOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->marketOrder->id)->toBe($existingMarket->id);
});

// ───────────────────────── PlaceProfitOrderJob::startOrFail ─────────────────────────

it('PlaceProfitOrder: passes when position is active with all required attributes set', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'profit_percentage' => '5.000',
    ]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeTrue();
});

it('PlaceProfitOrder: refuses when position is in a non-active status (closed/cancelled/failed)', function (string $terminalStatus): void {
    $position = Position::factory()->long()->create([
        'status' => $terminalStatus,
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'profit_percentage' => '5.000',
    ]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
]);

it('PlaceProfitOrder: refuses when opening_price is missing (MARKET fill not yet propagated)', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opening_price' => null,
        'quantity' => '5872',
        'profit_percentage' => '5.000',
    ]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceProfitOrder: refuses when quantity is missing', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opening_price' => '0.16490',
        'quantity' => null,
        'profit_percentage' => '5.000',
    ]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceProfitOrder: refuses when profit_percentage is missing (PrepareData snapshot lost)', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'profit_percentage' => null,
    ]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceProfitOrder: rehydrates an existing PROFIT-LIMIT row on retry instead of creating a duplicate', function (): void {
    // Without the retry guard, a worker crashed after Order::create but
    // before the local reference-field commit would on retry create a
    // SECOND TP row and place a SECOND TP on the exchange.
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'profit_percentage' => '5.000',
    ]);

    $existingTp = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.17314',
        'quantity' => '5872',
        'status' => 'NEW',
    ]);

    $job = new PlaceProfitOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->profitOrder->id)->toBe($existingTp->id);
});

it('PlaceProfitOrder: ignores a CANCELLED prior attempt and treats placement as fresh', function (): void {
    // CANCELLED rows are no longer on the exchange book — a fresh placement
    // is required. The status filter must exclude them.
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'profit_percentage' => '5.000',
    ]);

    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.17314',
        'quantity' => '5872',
        'status' => 'CANCELLED',
    ]);

    $job = new PlaceProfitOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->profitOrder)->toBeNull();
});

// ───────────────────────── PlaceStopLossOrderJob::startOrFail ─────────────────────────

it('PlaceStopLossOrder: rehydrates an existing STOP-MARKET row on retry instead of creating a duplicate', function (): void {
    // Without the retry guard, a worker crashed after Order::create but
    // before the local reference-field commit would on retry create a
    // SECOND SL row and place a SECOND SL on the exchange — the position's
    // order-cap observer would then reject one of them, leaving local
    // state inconsistent with the exchange book.
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'stop_market_percentage' => '20.000',
        'total_limit_orders' => 0,  // simple-trade mode — anchor = opening_price
    ]);

    $existingSl = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.13192',
        'quantity' => '5872',
        'status' => 'NEW',
    ]);

    $job = new PlaceStopLossOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->stopLossOrder->id)->toBe($existingSl->id);
});

it('PlaceStopLossOrder: ignores a CANCELLED prior attempt and treats placement as fresh', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opening_price' => '0.16490',
        'quantity' => '5872',
        'stop_market_percentage' => '20.000',
        'total_limit_orders' => 0,
    ]);

    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.13192',
        'quantity' => '5872',
        'status' => 'CANCELLED',
    ]);

    $job = new PlaceStopLossOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->stopLossOrder)->toBeNull();
});

// ───────────────────────── DispatchLimitOrdersJob::startOrFail ─────────────────────────

it('DispatchLimitOrders: passes when MARKET has filled (quantity + opening_price both set)', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'quantity' => '5872',
        'opening_price' => '0.16490',
        'total_limit_orders' => 4,
    ]);

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeTrue();
});

it('DispatchLimitOrders: refuses when quantity is null (MARKET not yet filled)', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'quantity' => null,
        'opening_price' => '0.16490',
        'total_limit_orders' => 4,
    ]);

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeFalse();
});

it('DispatchLimitOrders: refuses when opening_price is null (MARKET fill not yet propagated)', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'quantity' => '5872',
        'opening_price' => null,
        'total_limit_orders' => 4,
    ]);

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeFalse();
});

it('DispatchLimitOrders: passes even when total_limit_orders is 0 (simple-trade mode)', function (): void {
    // Simple-trade mode: the dispatch step still runs, but `compute()`
    // produces zero LIMIT rungs. The startOrFail gate is independent of
    // the ladder count — only of the MARKET fill state.
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'quantity' => '5872',
        'opening_price' => '0.16490',
        'total_limit_orders' => 0,
    ]);

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeTrue();
});
