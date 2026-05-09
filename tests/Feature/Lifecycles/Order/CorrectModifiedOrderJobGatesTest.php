<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the CorrectModifiedOrder gate. This atomic restores a LIMIT
 * order's price/quantity back to its `reference_*` values when the
 * exchange-reported value diverges (manual edit, partial-fill rounding,
 * sync race). Refusal cases:
 *
 *   - position not in active set → drift correction during a close
 *     cascade would re-place an order being torn down.
 *   - order not on this position → ID mismatch (cross-position guard).
 *   - order not in NEW/PARTIALLY_FILLED → can't modify a finished order.
 *   - is_algo=true → algo orders require cancel+recreate, not modify.
 *   - both reference_* fields null → nothing to restore.
 *   - no actual drift between current vs reference → no-op skip.
 */
function buildModifiedOrderScenario(array $orderAttrs = [], array $positionAttrs = []): array
{
    $position = Position::factory()->long()->create(array_merge([
        'status' => 'active',
        'total_limit_orders' => 4,
    ], $positionAttrs));

    $order = Order::create(array_merge([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.09',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'is_algo' => false,
    ], $orderAttrs));

    return ['position' => $position, 'order' => $order];
}

it('passes when status=active, order is non-algo NEW, and price has drifted', function (): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario();

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('refuses when position status is not in the active set', function (string $nonActive): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario(positionAttrs: ['status' => $nonActive]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
})->with([
    'closing' => ['closing'],
    'cancelling' => ['cancelling'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
]);

it('refuses when order does not belong to this position (cross-position id mismatch)', function (): void {
    $other = Position::factory()->long()->create(['status' => 'active', 'total_limit_orders' => 4]);
    ['order' => $order] = buildModifiedOrderScenario();

    expect((new CorrectModifiedOrderJob($other->id, $order->id))->startOrFail())->toBeFalse();
});

it('refuses when order status is not NEW or PARTIALLY_FILLED', function (string $finishedStatus): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario(['status' => $finishedStatus]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
})->with([
    'FILLED' => ['FILLED'],
    'CANCELLED' => ['CANCELLED'],
    'EXPIRED' => ['EXPIRED'],
]);

it('refuses an algo order (algos require cancel+recreate)', function (): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario([
        'type' => 'STOP-MARKET',
        'is_algo' => true,
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('refuses when both reference_price AND reference_quantity are null (nothing to restore)', function (): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario([
        'reference_price' => null,
        'reference_quantity' => null,
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('refuses when there is no actual drift between price/quantity and their references (Math::equal mirrors observer)', function (): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario([
        'price' => '0.10',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('refuses when price/quantity are numerically equal but stored at different decimal scales (the accessor-strip case)', function (): void {
    // Order::price accessor strips trailing zeros while reference_price
    // returns the raw DECIMAL(20,8) string. Strict `!==` would falsely
    // detect drift; Math::equal must match the observer's pattern.
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario([
        'price' => '1.4769',
        'reference_price' => '1.47690000',
        'quantity' => '100',
        'reference_quantity' => '100.00000000',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeFalse();
});

it('passes for quantity drift even when price matches', function (): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario([
        'price' => '0.10',
        'reference_price' => '0.10',
        'quantity' => '50',
        'reference_quantity' => '100',
    ]);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});

it('passes when order is PARTIALLY_FILLED (still mid-life on exchange)', function (): void {
    ['position' => $position, 'order' => $order] = buildModifiedOrderScenario(['status' => 'PARTIALLY_FILLED']);

    expect((new CorrectModifiedOrderJob($position->id, $order->id))->startOrFail())->toBeTrue();
});
