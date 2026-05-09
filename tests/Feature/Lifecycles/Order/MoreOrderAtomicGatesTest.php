<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Order\DispatchLimitOrdersJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the placement / sync / WAP atomic gates that the dispatch chain
 * relies on. Each one fronts a real-money exchange call — a regression
 * here ships either as silent skips (the order never gets placed and
 * the cascade hangs in `opening`) or as forced placements against a
 * half-prepared position (vendor errors, auth rejects, math against
 * null fields).
 */
function buildPlacementReadyPosition(array $overrides = []): Position
{
    return Position::factory()->long()->create(array_merge([
        'status' => 'opening',
        'opening_price' => '0.10',
        'quantity' => '100',
        'profit_percentage' => '0.350',
        'total_limit_orders' => 4,
    ], $overrides));
}

// ───────────────── PlaceProfitOrderJob::startOrFail ─────────────────

it('PlaceProfit: passes when status is active-set + opening_price + quantity + profit_percentage are all set', function (): void {
    $position = buildPlacementReadyPosition();

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeTrue();
});

it('PlaceProfit: refuses when status has left the active set (close cascade in flight)', function (string $nonActive): void {
    $position = buildPlacementReadyPosition(['status' => $nonActive]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'closing' => ['closing'],
    'cancelling' => ['cancelling'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
    'watching' => ['watching'],
]);

it('PlaceProfit: refuses when opening_price is null (market not yet filled)', function (): void {
    $position = buildPlacementReadyPosition(['opening_price' => null]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceProfit: refuses when quantity is null (market not yet filled)', function (): void {
    $position = buildPlacementReadyPosition(['quantity' => null]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('PlaceProfit: refuses when profit_percentage is null (config missing)', function (): void {
    $position = buildPlacementReadyPosition(['profit_percentage' => null]);

    expect((new PlaceProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────── DispatchLimitOrdersJob::startOrFail ─────────────────

it('DispatchLimits: passes when status is active-set + quantity + opening_price are set', function (): void {
    $position = buildPlacementReadyPosition();

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeTrue();
});

it('DispatchLimits: refuses when quantity is null (market not yet filled)', function (): void {
    $position = buildPlacementReadyPosition(['quantity' => null]);

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeFalse();
});

it('DispatchLimits: refuses when opening_price is null', function (): void {
    $position = buildPlacementReadyPosition(['opening_price' => null]);

    expect((new DispatchLimitOrdersJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────── CalculateWapAndModifyProfit::startOrFail ─────────────────

it('CalculateWap: passes when status=waping AND profit order exists AND profit_percentage is set', function (): void {
    $position = buildPlacementReadyPosition(['status' => 'waping']);

    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'reference_price' => '0.20',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'NEW',
        'reference_status' => 'NEW',
    ]);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeTrue();
});

it('CalculateWap: refuses when status is anything other than waping', function (string $other): void {
    $position = buildPlacementReadyPosition(['status' => $other]);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'active' => ['active'],
    'syncing' => ['syncing'],
    'opening' => ['opening'],
    'closing' => ['closing'],
    'closed' => ['closed'],
]);

it('CalculateWap: refuses when no profit order exists (TP got cancelled mid-flight)', function (): void {
    $position = buildPlacementReadyPosition(['status' => 'waping']);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('CalculateWap: refuses when profit_percentage is null (config bug)', function (): void {
    $position = buildPlacementReadyPosition(['status' => 'waping', 'profit_percentage' => null]);

    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'reference_price' => '0.20',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'NEW',
        'reference_status' => 'NEW',
    ]);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────── SyncPositionOrdersJob::startOrFail ─────────────────

it('SyncPositionOrders: passes when status is opened-set AND there is at least one syncable order', function (): void {
    $position = buildPlacementReadyPosition(['status' => 'active']);
    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'exchange_order_id' => 'L1',
        'price' => '0.09',
        'quantity' => '100',
        'status' => 'NEW',
        'reference_status' => 'NEW',
    ]);

    expect((new SyncPositionOrdersJob($position->id))->startOrFail())->toBeTrue();
});

it('SyncPositionOrders: refuses when no syncable orders exist (all MARKET, all unplaced)', function (): void {
    $position = buildPlacementReadyPosition(['status' => 'active']);
    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'exchange_order_id' => 'M1',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
    ]);

    expect((new SyncPositionOrdersJob($position->id))->startOrFail())->toBeFalse();
});

it('SyncPositionOrders: refuses when status is terminal (closed/cancelled/failed)', function (string $terminal): void {
    $position = buildPlacementReadyPosition(['status' => $terminal]);
    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'exchange_order_id' => 'L1',
        'price' => '0.09',
        'quantity' => '100',
        'status' => 'NEW',
        'reference_status' => 'NEW',
    ]);

    expect((new SyncPositionOrdersJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
]);
