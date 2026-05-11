<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Order\CancelSingleAlgoOrderJob;
use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob;
use Kraite\Core\Jobs\Atomic\Order\VerifyIfTPIsFilledJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the gating logic on the order-correction / cancel-algo / WAP /
 * TP-verification atomics. These run mid-life on a position and can
 * cascade into double-cancel, ghost orders, or accidental close-after-
 * close workflows when their guards regress. Each truth-table is
 * exercised against the canonical happy path plus every refusal branch
 * the production code ships today.
 */

// ───────────────────────── CorrectModifiedOrderJob::startOrFail ─────────────────────────

it('CorrectModifiedOrder: passes when active LIMIT has price drift from reference_price', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.11',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('CorrectModifiedOrder: passes when active LIMIT has quantity drift from reference_quantity', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10',
        'reference_price' => '0.10',
        'quantity' => '120',
        'reference_quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('CorrectModifiedOrder: refuses when no drift exists (price + quantity match reference)', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10000000',
        'reference_price' => '0.10000000',
        'quantity' => '100.00000000',
        'reference_quantity' => '100.00000000',
        'status' => 'NEW',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('CorrectModifiedOrder: refuses on algo orders (require cancel+recreate, not modify)', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.05',
        'reference_price' => '0.04',
        'is_algo' => true,
        'quantity' => '0',
        'reference_quantity' => '0',
        'status' => 'NEW',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('CorrectModifiedOrder: refuses when both reference_price and reference_quantity are null (nothing to restore)', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.11',
        'reference_price' => null,
        'quantity' => '100',
        'reference_quantity' => null,
        'status' => 'NEW',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('CorrectModifiedOrder: refuses when order belongs to another position', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $other = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    $order = Order::create([
        'position_id' => $other->id,
        'side' => 'BUY',
        'position_side' => $other->direction,
        'type' => 'LIMIT',
        'price' => '0.11',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

// ───────────────────────── CancelSingleAlgoOrderJob::startOrFail ─────────────────────────

it('CancelSingleAlgoOrder: passes when an active algo SL/TP exists with an exchange_order_id', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.05',
        'is_algo' => true,
        'exchange_order_id' => '1000001596054504',
        'quantity' => '0',
        'status' => 'NEW',
    ]);

    expect((new CancelSingleAlgoOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('CancelSingleAlgoOrder: refuses when the order has no exchange_order_id (never placed remotely)', function (): void {
    // Ghost rows where placement threw before reaching the exchange must
    // not flow into the cancel API — the mapper would error on missing
    // algo_id and the step would loop forever.
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.05',
        'is_algo' => true,
        'exchange_order_id' => null,
        'quantity' => '0',
        'status' => 'NEW',
    ]);

    expect((new CancelSingleAlgoOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('CancelSingleAlgoOrder: refuses non-algo orders', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10',
        'is_algo' => false,
        'exchange_order_id' => '99',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CancelSingleAlgoOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('CancelSingleAlgoOrder: refuses orders already in a terminal status', function (string $terminalStatus): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.05',
        'is_algo' => true,
        'exchange_order_id' => '99',
        'quantity' => '0',
        'status' => $terminalStatus,
    ]);

    expect((new CancelSingleAlgoOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
})->with([
    'CANCELLED' => ['CANCELLED'],
    'EXPIRED' => ['EXPIRED'],
    'FILLED' => ['FILLED'],
]);

it('CancelSingleAlgoOrder: refuses when the position is mid-flight in cancelling/closing', function (string $midFlightStatus): void {
    // Mid-flight states: cancel-all is already running. A second cancel
    // attempt can race the active workflow.
    $position = Position::factory()->long()->create(['status' => $midFlightStatus]);
    $order = Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'price' => '0.05',
        'is_algo' => true,
        'exchange_order_id' => '99',
        'quantity' => '0',
        'status' => 'NEW',
    ]);

    expect((new CancelSingleAlgoOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
})->with([
    'cancelling' => ['cancelling'],
    'closing' => ['closing'],
]);

// ───────────────────────── CalculateWapAndModifyProfitOrderJob::startOrFail ─────────────────────────

it('CalculateWap: passes only when position is in waping with a profit order and profit_percentage', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'waping',
        'profit_percentage' => '0.36',
    ]);
    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeTrue();
});

it('CalculateWap: refuses when position is not in waping (status guard)', function (string $nonWapingStatus): void {
    $position = Position::factory()->long()->create([
        'status' => $nonWapingStatus,
        'profit_percentage' => '0.36',
    ]);
    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'active' => ['active'],
    'syncing' => ['syncing'],
    'closing' => ['closing'],
    'opening' => ['opening'],
]);

it('CalculateWap: refuses when no live profit order exists', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'waping',
        'profit_percentage' => '0.36',
    ]);
    // No profit order created.

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('CalculateWap: refuses when profit_percentage is null', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'waping',
        'profit_percentage' => null,
    ]);
    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new CalculateWapAndModifyProfitOrderJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────────────── VerifyIfTPIsFilledJob::startOrFail ─────────────────────────

it('VerifyIfTPIsFilled: passes when the position has a live profit order to verify', function (): void {
    $position = Position::factory()->long()->create(['status' => 'waping']);
    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new VerifyIfTPIsFilledJob($position->id))->startOrFail())->toBeTrue();
});

it('VerifyIfTPIsFilled: refuses when no profit order is attached', function (): void {
    $position = Position::factory()->long()->create(['status' => 'waping']);

    expect((new VerifyIfTPIsFilledJob($position->id))->startOrFail())->toBeFalse();
});

it('VerifyIfTPIsFilled: refuses when the only profit order is CANCELLED (not "live")', function (): void {
    $position = Position::factory()->long()->create(['status' => 'waping']);
    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'PROFIT-LIMIT',
        'price' => '0.20',
        'quantity' => '100',
        'status' => 'CANCELLED',
    ]);

    expect((new VerifyIfTPIsFilledJob($position->id))->startOrFail())->toBeFalse();
});
