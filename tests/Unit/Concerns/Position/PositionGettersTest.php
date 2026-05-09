<?php

declare(strict_types=1);

use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the read accessors on Position. Every close, WAP, drift, and
 * activation step relies on these getters to find the right row in a
 * forest of LIMIT / MARKET / TP / SL records — a wrong return here
 * cascades into a wrong action: SL anchored to a CANCELLED rung, WAP
 * fired on a stale entry, drift correction targeting a closed order.
 *
 * Each scenario builds a position with a hand-rolled order fixture and
 * asserts the getter picks the expected row, ignoring the noise.
 */
function newPositionForGetters(): Position
{
    return Position::factory()->long()->create(['total_limit_orders' => 4]);
}

function makePositionGetterOrder(Position $position, array $attrs = []): Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10000',
        'quantity' => '100',
        'status' => 'NEW',
    ], $attrs));
}

// ───────────────────────── lastLimitOrder ─────────────────────────

it('lastLimitOrder returns null when no LIMIT orders exist', function (): void {
    $position = newPositionForGetters();
    expect($position->lastLimitOrder())->toBeNull();
});

it('lastLimitOrder returns the LIMIT with the highest quantity (deepest rung)', function (): void {
    // Martingale ladder: deeper rungs carry larger quantities. The SL
    // anchors against the deepest rung, so this getter must always
    // pick the largest-qty LIMIT, regardless of insertion order.
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'price' => '0.09', 'quantity' => '100', 'exchange_order_id' => 'A']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'price' => '0.08', 'quantity' => '400', 'exchange_order_id' => 'B']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'price' => '0.085', 'quantity' => '200', 'exchange_order_id' => 'C']);

    expect($position->lastLimitOrder()->exchange_order_id)->toBe('B');
});

it('lastLimitOrder ignores orders without exchange_order_id (never placed remotely)', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'quantity' => '999', 'exchange_order_id' => null]);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'quantity' => '50', 'exchange_order_id' => 'B']);

    expect($position->lastLimitOrder()->exchange_order_id)->toBe('B');
});

it('lastLimitOrder ignores CANCELLED and EXPIRED LIMITs', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'quantity' => '999', 'exchange_order_id' => 'X', 'status' => 'CANCELLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'quantity' => '500', 'exchange_order_id' => 'Y', 'status' => 'EXPIRED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'quantity' => '10', 'exchange_order_id' => 'Z', 'status' => 'NEW']);

    expect($position->lastLimitOrder()->exchange_order_id)->toBe('Z');
});

it('lastLimitOrder accepts NEW, PARTIALLY_FILLED, and FILLED status rows', function (string $status): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'quantity' => '100', 'exchange_order_id' => 'A', 'status' => $status]);

    expect($position->lastLimitOrder())->not->toBeNull();
})->with([
    'NEW' => ['NEW'],
    'PARTIALLY_FILLED' => ['PARTIALLY_FILLED'],
    'FILLED' => ['FILLED'],
]);

// ───────────────────────── totalLimitOrdersFilled ─────────────────────────

it('totalLimitOrdersFilled returns 0 when no LIMIT has filled', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'NEW']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'PARTIALLY_FILLED']);
    expect($position->totalLimitOrdersFilled())->toBe(0);
});

it('totalLimitOrdersFilled counts only FILLED LIMITs (PARTIALLY_FILLED does not count)', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'PARTIALLY_FILLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'FILLED']);
    expect($position->totalLimitOrdersFilled())->toBe(2);
});

it('totalLimitOrdersFilled ignores non-LIMIT order types', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'MARKET', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'PROFIT-LIMIT', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'FILLED']);
    expect($position->totalLimitOrdersFilled())->toBe(1);
});

// ───────────────────────── allLimitOrdersFilled ─────────────────────────

it('allLimitOrdersFilled returns true when filled count matches total_limit_orders (martingale fully consumed)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 2]);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'FILLED']);
    expect($position->allLimitOrdersFilled())->toBeTrue();
});

it('allLimitOrdersFilled returns true on a 0-limit position even when nothing has filled (vacuously satisfied)', function (): void {
    // Simple-trade mode: total_limit_orders=0 has no rungs to fill, so
    // "all are filled" is true by definition. Drift / WAP code branches
    // that gate on this getter must not refuse to fire on simple trades.
    $position = Position::factory()->long()->create(['total_limit_orders' => 0]);
    expect($position->allLimitOrdersFilled())->toBeTrue();
});

it('allLimitOrdersFilled returns false when partial', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'status' => 'FILLED']);
    expect($position->allLimitOrdersFilled())->toBeFalse();
});

// ───────────────────────── marketOrder / stopMarketOrder / profitOrder ─────────────────────────

it('marketOrder returns the latest MARKET order', function (): void {
    // OrderObserver enforces "one active MARKET per position" using its
    // own INACTIVE_STATUSES set (CANCELLED, EXPIRED only — FILLED still
    // counts as active for the slot guard). To exercise the "latest
    // among many" path, the first MARKET has to be CANCELLED before
    // the second is allowed in.
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'MARKET', 'price' => '0.1', 'status' => 'CANCELLED']);
    $second = makePositionGetterOrder($position, ['type' => 'MARKET', 'price' => '0.11', 'status' => 'NEW']);
    expect($position->marketOrder()->id)->toBe($second->id);
});

it('marketOrder returns null when none exists', function (): void {
    expect(newPositionForGetters()->marketOrder())->toBeNull();
});

it('stopMarketOrder returns the latest STOP-MARKET order', function (): void {
    // Only one active STOP-MARKET per position; first one terminal so
    // the second is allowed by the observer guard.
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'STOP-MARKET', 'price' => '0.05', 'is_algo' => true, 'status' => 'CANCELLED']);
    $latest = makePositionGetterOrder($position, ['type' => 'STOP-MARKET', 'price' => '0.06', 'is_algo' => true, 'status' => 'NEW']);
    expect($position->stopMarketOrder()->id)->toBe($latest->id);
});

it('profitOrder excludes CANCELLED and EXPIRED rows', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'PROFIT-LIMIT', 'status' => 'CANCELLED']);
    makePositionGetterOrder($position, ['type' => 'PROFIT-LIMIT', 'status' => 'EXPIRED']);
    $live = makePositionGetterOrder($position, ['type' => 'PROFIT-LIMIT', 'status' => 'NEW']);

    expect($position->profitOrder()->id)->toBe($live->id);
});

it('profitOrder includes FILLED so the close path can detect a just-filled TP', function (): void {
    // Close detection: VerifyIfTPIsFilledJob queries this getter expecting
    // to see a FILLED TP. Filtering FILLED out would break the close flow.
    $position = newPositionForGetters();
    $tp = makePositionGetterOrder($position, ['type' => 'PROFIT-LIMIT', 'status' => 'FILLED']);
    expect($position->profitOrder()->id)->toBe($tp->id);
});

// ───────────────────────── averageEntryPrice ─────────────────────────

it('averageEntryPrice returns null when no fills exist', function (): void {
    expect(newPositionForGetters()->averageEntryPrice())->toBeNull();
});

it('averageEntryPrice computes the cost-weighted average across MARKET + LIMIT fills', function (): void {
    $position = newPositionForGetters();
    // 100 @ 0.10 = 10 cost; 200 @ 0.05 = 10 cost; total cost 20, total qty 300, avg = 0.066666...
    makePositionGetterOrder($position, ['type' => 'MARKET', 'price' => '0.10', 'quantity' => '100', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'price' => '0.05', 'quantity' => '200', 'status' => 'FILLED']);

    $avg = $position->averageEntryPrice();
    expect($avg)->not->toBeNull()
        ->and(rtrim(rtrim($avg, '0'), '.'))->toBe('0.06666666');
});

it('averageEntryPrice ignores non-FILLED rows (NEW, CANCELLED, etc.)', function (): void {
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'MARKET', 'price' => '0.10', 'quantity' => '100', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'price' => '999', 'quantity' => '999', 'status' => 'NEW']);
    makePositionGetterOrder($position, ['type' => 'LIMIT', 'price' => '999', 'quantity' => '999', 'status' => 'CANCELLED']);

    expect(rtrim(rtrim($position->averageEntryPrice(), '0'), '.'))->toBe('0.1');
});

it('averageEntryPrice ignores TP and SL order types', function (): void {
    // TP/SL fills are EXIT events, not entry — must not contaminate the
    // weighted-average ENTRY computation.
    $position = newPositionForGetters();
    makePositionGetterOrder($position, ['type' => 'MARKET', 'price' => '0.10', 'quantity' => '100', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'PROFIT-LIMIT', 'price' => '0.20', 'quantity' => '100', 'status' => 'FILLED']);
    makePositionGetterOrder($position, ['type' => 'STOP-MARKET', 'price' => '0.05', 'quantity' => '100', 'status' => 'FILLED', 'is_algo' => true]);

    expect(rtrim(rtrim($position->averageEntryPrice(), '0'), '.'))->toBe('0.1');
});
