<?php

declare(strict_types=1);

use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

/**
 * Helper to create a position with a set number of limit order slots.
 */
function createTestPosition(int $totalLimitOrders = 4): Position
{
    return Position::factory()->long()->create([
        'total_limit_orders' => $totalLimitOrders,
    ]);
}

/**
 * Helper to create an order on a position.
 */
function createOrderOnPosition(Position $position, array $attributes = []): Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '40000.00',
        'quantity' => '0.001',
        'position_side' => $position->direction,
        'status' => 'NEW',
    ], $attributes));
}

// --- STOP-MARKET restrictions ---

it('allows creating a STOP-MARKET order when none exists', function () {
    $position = createTestPosition();

    $order = createOrderOnPosition($position, ['type' => 'STOP-MARKET']);

    expect($order->exists)->toBeTrue();
});

it('blocks creating a second active STOP-MARKET order', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'STOP-MARKET', 'status' => 'NEW']);

    createOrderOnPosition($position, ['type' => 'STOP-MARKET']);
})->throws(NonNotifiableException::class, 'STOP-MARKET order creation blocked');

it('allows STOP-MARKET when existing one is CANCELLED', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'STOP-MARKET', 'status' => 'CANCELLED']);

    $order = createOrderOnPosition($position, ['type' => 'STOP-MARKET']);

    expect($order->exists)->toBeTrue();
});

it('allows STOP-MARKET when existing one is EXPIRED', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'STOP-MARKET', 'status' => 'EXPIRED']);

    $order = createOrderOnPosition($position, ['type' => 'STOP-MARKET']);

    expect($order->exists)->toBeTrue();
});

// --- MARKET restrictions ---

it('allows creating a MARKET order when none exists', function () {
    $position = createTestPosition();

    $order = createOrderOnPosition($position, ['type' => 'MARKET']);

    expect($order->exists)->toBeTrue();
});

it('blocks creating a second active MARKET order', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'MARKET', 'status' => 'NEW']);

    createOrderOnPosition($position, ['type' => 'MARKET']);
})->throws(NonNotifiableException::class, 'MARKET order creation blocked');

it('allows MARKET when existing one is CANCELLED', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'MARKET', 'status' => 'CANCELLED']);

    $order = createOrderOnPosition($position, ['type' => 'MARKET']);

    expect($order->exists)->toBeTrue();
});

// --- MARKET-CANCEL restrictions ---

it('blocks creating a second active MARKET-CANCEL order', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'MARKET-CANCEL', 'status' => 'NEW']);

    createOrderOnPosition($position, ['type' => 'MARKET-CANCEL']);
})->throws(NonNotifiableException::class, 'MARKET-CANCEL order creation blocked');

// --- PROFIT restrictions ---

it('allows creating a PROFIT-LIMIT order when none exists', function () {
    $position = createTestPosition();

    $order = createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT']);

    expect($order->exists)->toBeTrue();
});

it('blocks creating a second active PROFIT-LIMIT order', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);

    createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT']);
})->throws(NonNotifiableException::class, 'PROFIT order creation blocked');

it('blocks PROFIT-MARKET when active PROFIT-LIMIT exists', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);

    createOrderOnPosition($position, ['type' => 'PROFIT-MARKET']);
})->throws(NonNotifiableException::class, 'PROFIT order creation blocked');

it('allows PROFIT-LIMIT when existing profit order is CANCELLED', function () {
    $position = createTestPosition();
    createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'CANCELLED']);

    $order = createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT']);

    expect($order->exists)->toBeTrue();
});

// --- LIMIT restrictions ---

it('allows creating LIMIT orders up to total_limit_orders', function () {
    $position = createTestPosition(totalLimitOrders: 3);

    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '39000']);
    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '38000']);
    $third = createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '37000']);

    expect($third->exists)->toBeTrue();
});

it('blocks creating LIMIT order beyond total_limit_orders', function () {
    $position = createTestPosition(totalLimitOrders: 2);

    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '39000']);
    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '38000']);

    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '37000']);
})->throws(NonNotifiableException::class, 'LIMIT order creation blocked');

it('allows LIMIT when cancelled orders free up slots', function () {
    $position = createTestPosition(totalLimitOrders: 2);

    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '39000', 'status' => 'CANCELLED']);
    createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '38000']);

    $order = createOrderOnPosition($position, ['type' => 'LIMIT', 'price' => '37000']);

    expect($order->exists)->toBeTrue();
});

// --- Close position dispatch on fill ---

it('dispatches ClosePositionJob when a PROFIT-LIMIT order is filled', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);

    $order->update(['status' => 'FILLED']);

    $step = Step::where('class', ClosePositionJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($order->fresh()->reference_status)->toBe('FILLED');
});

it('dispatches ClosePositionJob when a STOP-MARKET order is filled', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'STOP-MARKET', 'status' => 'NEW']);

    $order->update(['status' => 'FILLED']);

    $step = Step::where('class', ClosePositionJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($order->fresh()->reference_status)->toBe('FILLED');
});

it('does not dispatch ClosePositionJob when a LIMIT order is filled', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'LIMIT', 'status' => 'NEW']);

    $order->update(['status' => 'FILLED']);

    expect(Step::where('class', ClosePositionJob::class)->exists())->toBeFalse();
});

it('does not dispatch ClosePositionJob when position is already closed', function () {
    $position = createTestPosition();
    $position->update(['status' => 'closed']);

    $order = createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);
    $order->update(['status' => 'FILLED']);

    expect(Step::where('class', ClosePositionJob::class)->exists())->toBeFalse();
});

it('does not dispatch ClosePositionJob when reference_status is already FILLED', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
    ]);

    // Simulate a second sync updating the same order
    $order->update(['status' => 'FILLED']);

    expect(Step::where('class', ClosePositionJob::class)->exists())->toBeFalse();
});

// --- Position replacement dispatch on expired/cancelled ---

it('dispatches PreparePositionReplacementJob when a PROFIT-LIMIT order expires', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);

    $order->update(['status' => 'EXPIRED']);

    $step = Step::where('class', PreparePositionReplacementJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($step->arguments['triggerStatus'])->toBe('EXPIRED');
    // Note: reference_status is NOT updated immediately for replacements.
    // SmartReplaceOrdersJob uses reference_status != status to find unhandled orders.
    // RecreateCancelledOrderJob::complete() updates it after successful recreation.
});

it('dispatches PreparePositionReplacementJob when a STOP-MARKET order expires', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'STOP-MARKET', 'status' => 'NEW']);

    $order->update(['status' => 'EXPIRED']);

    $step = Step::where('class', PreparePositionReplacementJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($step->arguments['triggerStatus'])->toBe('EXPIRED');
    // Note: reference_status is NOT updated immediately for replacements.
});

it('dispatches PreparePositionReplacementJob when a PROFIT-LIMIT order is cancelled', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);

    $order->update(['status' => 'CANCELLED']);

    $step = Step::where('class', PreparePositionReplacementJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($step->arguments['triggerStatus'])->toBe('CANCELLED');
    // Note: reference_status is NOT updated immediately for replacements.
});

it('dispatches PreparePositionReplacementJob when a STOP-MARKET order is cancelled', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, ['type' => 'STOP-MARKET', 'status' => 'NEW']);

    $order->update(['status' => 'CANCELLED']);

    $step = Step::where('class', PreparePositionReplacementJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($step->arguments['triggerStatus'])->toBe('CANCELLED');
    // Note: reference_status is NOT updated immediately for replacements.
});

it('does not dispatch PreparePositionReplacementJob when reference_status is already EXPIRED', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'EXPIRED',
        'reference_status' => 'EXPIRED',
    ]);

    $order->update(['status' => 'EXPIRED']);

    expect(Step::where('class', PreparePositionReplacementJob::class)->exists())->toBeFalse();
});

it('does not dispatch PreparePositionReplacementJob when reference_status is already CANCELLED', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'CANCELLED',
        'reference_status' => 'CANCELLED',
    ]);

    $order->update(['status' => 'CANCELLED']);

    expect(Step::where('class', PreparePositionReplacementJob::class)->exists())->toBeFalse();
});

// --- Order modification detection ---

it('dispatches PrepareOrderCorrectionJob when a LIMIT order price is modified', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00',
        'reference_price' => '40000.00',
    ]);

    // Simulate price modification detected during sync
    $order->update(['price' => '39500.00']);

    $step = Step::where('class', PrepareOrderCorrectionJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($step->arguments['orderId'])->toBe($order->id);
});

it('dispatches PrepareOrderCorrectionJob when a LIMIT order quantity is modified', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'quantity' => '0.001',
        'reference_quantity' => '0.001',
    ]);

    // Simulate quantity modification detected during sync
    $order->update(['quantity' => '0.0005']);

    $step = Step::where('class', PrepareOrderCorrectionJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($step->arguments['orderId'])->toBe($order->id);
});

it('dispatches PrepareOrderCorrectionJob when price and quantity are both modified', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00',
        'quantity' => '0.001',
        'reference_price' => '40000.00',
        'reference_quantity' => '0.001',
    ]);

    // Simulate both price and quantity modification
    $order->update(['price' => '39500.00', 'quantity' => '0.0005']);

    $step = Step::where('class', PrepareOrderCorrectionJob::class)->first();
    expect($step)->not->toBeNull();
});

it('does not dispatch correction job when order has no reference values', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00',
        // No reference_price or reference_quantity set
    ]);

    $order->update(['price' => '39500.00']);

    expect(Step::where('class', PrepareOrderCorrectionJob::class)->exists())->toBeFalse();
});

it('does not dispatch correction job when values match reference', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00',
        'reference_price' => '40000.00',
    ]);

    // Update with same price - no drift
    $order->update(['price' => '40000.00']);

    expect(Step::where('class', PrepareOrderCorrectionJob::class)->exists())->toBeFalse();
});

it('does not false-positive on decimal precision differences', function () {
    // This test verifies the fix for the cascade bug where string comparison
    // of decimals with different trailing zeros caused false modification detection.
    // The price accessor normalizes "40000.00000000" to "40000" but raw reference
    // values retain full precision - Math::equal() handles this correctly.
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00000000',
        'reference_price' => '40000',
    ]);

    // Trigger an update that would invoke the observer
    $order->update(['status' => 'NEW']);

    // No drift should be detected - values are numerically equal
    expect(Step::where('class', PrepareOrderCorrectionJob::class)->exists())->toBeFalse();
});

it('does not dispatch correction job when order is not active', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'FILLED',
        'price' => '40000.00',
        'reference_price' => '40000.00',
    ]);

    // Update price on filled order - should not trigger correction
    $order->update(['price' => '39500.00']);

    expect(Step::where('class', PrepareOrderCorrectionJob::class)->exists())->toBeFalse();
});

it('does not dispatch correction job when position is not active', function () {
    $position = createTestPosition();
    $position->update(['status' => 'closed']);

    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00',
        'reference_price' => '40000.00',
    ]);

    $order->update(['price' => '39500.00']);

    expect(Step::where('class', PrepareOrderCorrectionJob::class)->exists())->toBeFalse();
});

it('dispatches correction job for PARTIALLY_FILLED orders', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'PARTIALLY_FILLED',
        'price' => '40000.00',
        'reference_price' => '40000.00',
    ]);

    $order->update(['price' => '39500.00']);

    expect(Step::where('class', PrepareOrderCorrectionJob::class)->exists())->toBeTrue();
});

it('deduplicates correction job dispatch', function () {
    $position = createTestPosition();
    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '40000.00',
        'reference_price' => '40000.00',
    ]);

    // First modification
    $order->update(['price' => '39500.00']);

    // Second modification with same order (should not create another step)
    $order->update(['price' => '39000.00']);

    $steps = Step::where('class', PrepareOrderCorrectionJob::class)->get();
    expect($steps)->toHaveCount(1);
});

// --- WAP (Weighted Average Price) dispatch on LIMIT fill ---

it('dispatches ApplyWapJob when a LIMIT order is filled', function () {
    $position = createTestPosition();
    $position->update(['profit_percentage' => '0.350']);

    // Create a profit order (required for WAP)
    createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
        'price' => '41000.00',
    ]);

    $order = createOrderOnPosition($position, ['type' => 'LIMIT', 'status' => 'NEW']);

    $order->update(['status' => 'FILLED']);

    $step = Step::where('class', Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob::class)->first();
    expect($step)->not->toBeNull();
    expect($step->arguments['positionId'])->toBe($position->id);
    expect($order->fresh()->reference_status)->toBe('FILLED');
});

it('does not dispatch ApplyWapJob when position is not active', function () {
    $position = createTestPosition();
    $position->update([
        'status' => 'closed',
        'profit_percentage' => '0.350',
    ]);

    createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
    ]);

    $order = createOrderOnPosition($position, ['type' => 'LIMIT', 'status' => 'NEW']);
    $order->update(['status' => 'FILLED']);

    expect(Step::where('class', Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob::class)->exists())->toBeFalse();
});

it('does not dispatch ApplyWapJob when reference_status is already FILLED', function () {
    $position = createTestPosition();
    $position->update(['profit_percentage' => '0.350']);

    createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
    ]);

    $order = createOrderOnPosition($position, [
        'type' => 'LIMIT',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
    ]);

    // Simulate a second sync updating the same order
    $order->update(['status' => 'FILLED']);

    expect(Step::where('class', Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob::class)->exists())->toBeFalse();
});

it('deduplicates ApplyWapJob dispatch for same position', function () {
    $position = createTestPosition();
    $position->update(['profit_percentage' => '0.350']);

    createOrderOnPosition($position, [
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
    ]);

    $order1 = createOrderOnPosition($position, ['type' => 'LIMIT', 'status' => 'NEW', 'price' => '39000']);
    $order2 = createOrderOnPosition($position, ['type' => 'LIMIT', 'status' => 'NEW', 'price' => '38000']);

    // Both orders fill (e.g., rapid price movement)
    $order1->update(['status' => 'FILLED']);
    $order2->update(['status' => 'FILLED']);

    $steps = Step::where('class', Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob::class)->get();
    expect($steps)->toHaveCount(1);
});
