<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the recreation gate. After a CANCELLED / EXPIRED order is detected
 * (DCA cancelled mid-life, TP expired during close, SL replaced after
 * partial fill), this job rebuilds the missing rung. The gate is the
 * difference between a clean rebuild and a duplicate order on the
 * exchange — false positives ship as a position carrying TWO live
 * STOP-MARKET algos at slightly different prices, which is its own
 * incident class.
 *
 * The closePosition-aware quantity carve-out is also pinned: regular
 * LIMITs need positive remaining qty to be recreatable; algo close-all
 * orders (Binance STOP_MARKET with closePosition=true) carry quantity=0
 * by design and must still be allowed through.
 */
function buildRecreateScenario(array $orderAttrs = [], string $positionStatus = 'active'): array
{
    $position = Position::factory()->long()->create([
        'status' => $positionStatus,
        'total_limit_orders' => 4,
    ]);

    // `filled_quantity` is intentionally NOT a column on `orders` (lives on
    // `api_data_stream` instead), so the test fixture must not pass it
    // through. The recreate job's calculateRemainingQuantity() reads it
    // off the model dynamically and falls back to '0' when the property
    // is unset, which is the production-true shape.
    $attrs = array_merge([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.1',
        'reference_price' => '0.1',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'CANCELLED',
    ], $orderAttrs);

    // `filled_quantity` test attributes must be applied AFTER persisting,
    // since they aren't real columns — they ride on the model instance
    // for the duration of the test only.
    $filledOverride = $attrs['filled_quantity'] ?? null;
    unset($attrs['filled_quantity']);

    $order = Order::create($attrs);

    if ($filledOverride !== null) {
        $order->filled_quantity = $filledOverride;
    }

    return ['position' => $position, 'order' => $order];
}

// ───────────────────────── happy path ─────────────────────────

it('passes when an active position has a CANCELLED LIMIT with remaining quantity', function (): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario();

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('passes when the cancelled order is EXPIRED (TP expired during a close window)', function (): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario([
        'type' => 'PROFIT-LIMIT',
        'status' => 'EXPIRED',
    ]);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('passes when the order is REJECTED and the position still needs protection', function (): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario([
        'type' => 'PROFIT-LIMIT',
        'status' => 'REJECTED',
    ]);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

// ───────────────────────── status guards ─────────────────────────

it('refuses when the position has already terminated (closed/cancelled/failed)', function (string $terminalStatus): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario(positionStatus: $terminalStatus);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
})->with([
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
]);

it('refuses when the order is still active (NEW or PARTIALLY_FILLED)', function (string $liveStatus): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario(['status' => $liveStatus]);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
})->with([
    'NEW' => ['NEW'],
    'PARTIALLY_FILLED' => ['PARTIALLY_FILLED'],
    'FILLED' => ['FILLED'],
]);

// ───────────────────────── ownership + missing data guards ─────────────────────────

it('refuses when the order belongs to a different position (cross-position id mismatch)', function (): void {
    $otherPosition = Position::factory()->long()->create();
    ['order' => $order] = buildRecreateScenario();

    expect((new RecreateCancelledOrderJob($otherPosition->id, $order->id))->startOrFail())->toBeFalse();
});

it('refuses when the cancelled order has no price (corrupt row)', function (): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario();
    $order->update(['price' => null]);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

// ───────────────────────── quantity gate ─────────────────────────

it('refuses a LIMIT recreation when reference quantity itself is zero', function (): void {
    // The production code computes remaining = reference_quantity -
    // filled_quantity, and `filled_quantity` is not a real `orders`
    // column today (lives on api_data_stream). So the only path to
    // remaining<=0 for a non-algo order is reference_quantity already
    // being zero — which itself is a corrupt-row signal worth refusing.
    ['position' => $position, 'order' => $order] = buildRecreateScenario([
        'reference_quantity' => '0',
        'quantity' => '0',
    ]);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('passes a closePosition-style algo (STOP-MARKET, qty=0, is_algo=true) — quantity gate is bypassed', function (): void {
    // Binance closePosition algos canonically carry reference_quantity=0
    // because the algo flattens whatever is open at trigger time. The
    // recreate gate must NOT refuse to rebuild them just because their
    // numeric quantity diff is zero.
    ['position' => $position, 'order' => $order] = buildRecreateScenario([
        'type' => 'STOP-MARKET',
        'is_algo' => true,
        'quantity' => '0',
        'reference_quantity' => '0',
        'filled_quantity' => '0',
    ]);

    expect((new RecreateCancelledOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

// ───────────────────────── calculateRemainingQuantity ─────────────────────────

it('calculateRemainingQuantity returns reference_quantity when filled_quantity is unset (production-true shape)', function (): void {
    // `filled_quantity` is not a real column on `orders` today, so the
    // null-coalesce in calculateRemainingQuantity falls back to '0'
    // and the result equals reference_quantity untouched. Pinning
    // this so a future migration that adds the column doesn't
    // silently change recreation amounts without a regression flag.
    ['position' => $position, 'order' => $order] = buildRecreateScenario([
        'reference_quantity' => '100',
    ]);

    $job = new RecreateCancelledOrderJob($position->id, $order->id);
    expect(mb_rtrim(mb_rtrim($job->calculateRemainingQuantity(), '0'), '.'))->toBe('100');
});

it('calculateRemainingQuantity falls back to quantity when reference_quantity is null', function (): void {
    ['position' => $position, 'order' => $order] = buildRecreateScenario([
        'quantity' => '120',
        'reference_quantity' => null,
    ]);

    $job = new RecreateCancelledOrderJob($position->id, $order->id);
    expect(mb_rtrim(mb_rtrim($job->calculateRemainingQuantity(), '0'), '.'))->toBe('120');
});
