<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\ActivatePositionJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the activation-side validation contract. ActivatePositionJob is
 * the LAST step in the dispatch lifecycle — it's the gate that promotes
 * a position from `opening` → `active` only when every order shape on
 * the exchange and in the DB matches expectations. A regression here
 * would either:
 *
 *   - Activate a position with a half-placed order set (false negative)
 *   - Refuse a legitimately-placed position and trigger the cancel
 *     cascade for a real-money fill (false positive — see Position
 *     #577 / TONUSDT, 2026-05-06).
 *
 * Each test below builds a position + order graph that mirrors a
 * known production shape and asserts the validation logic agrees.
 *
 * NOTE: validateMarketOrders has a 1.5s polling fallback against the
 * exchange when MARKET is non-FILLED. Tests don't exercise that path
 * (no live exchange) — only the FILLED happy path and the count
 * mismatches. The polling logic is exercised by the WS integration
 * tests separately.
 */
function buildActivatableScenario(int $totalLimitOrders = 4): Position
{
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'total_limit_orders' => $totalLimitOrders,
        'opening_price' => '0.10',
        'quantity' => '100',
    ]);

    // 1 MARKET (filled)
    Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'price' => '0.10',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
    ]);

    // N LIMITs (NEW)
    for ($i = 0; $i < $totalLimitOrders; $i++) {
        Order::create([
            'position_id' => $position->id,
            'side' => 'BUY',
            'position_side' => $position->direction,
            'type' => 'LIMIT',
            'price' => '0.0'.(9 - $i),
            'reference_price' => '0.0'.(9 - $i),
            'quantity' => '50',
            'reference_quantity' => '50',
            'status' => 'NEW',
            'reference_status' => 'NEW',
        ]);
    }

    // 1 TP (NEW)
    Order::create([
        'position_id' => $position->id,
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

    // 1 SL (NEW, algo, qty=0 valid)
    Order::create([
        'position_id' => $position->id,
        'side' => 'SELL',
        'position_side' => $position->direction,
        'type' => 'STOP-MARKET',
        'is_algo' => true,
        'price' => '0.05',
        'reference_price' => '0.05',
        'quantity' => '0',
        'reference_quantity' => '0',
        'status' => 'NEW',
        'reference_status' => 'NEW',
    ]);

    return $position;
}

it('passes a textbook 4-LIMIT activation: 1 MARKET FILLED + 4 LIMIT NEW + 1 TP NEW + 1 SL NEW', function (): void {
    $position = buildActivatableScenario(totalLimitOrders: 4);

    $result = (new ActivatePositionJob($position->id))->compute();

    expect($result['status'])->toBe('validated')
        ->and($result['total_orders'])->toBe(7) // 1+4+1+1
        ->and($result['market_orders'])->toBe(1)
        ->and($result['limit_orders'])->toBe(4)
        ->and($result['tp_orders'])->toBe(1)
        ->and($result['sl_orders'])->toBe(1);
});

it('passes a simple-trade activation: 1 MARKET + 0 LIMIT + 1 TP + 1 SL = 3 orders', function (): void {
    // Pin the simple-trade mode shape — total_limit_orders=0 means the
    // expected count is 1 + 0 + 2 = 3. Test catches a regression
    // where a future edit to validateLimitOrders requires N>=1.
    $position = buildActivatableScenario(totalLimitOrders: 0);

    $result = (new ActivatePositionJob($position->id))->compute();

    expect($result['status'])->toBe('validated')
        ->and($result['total_orders'])->toBe(3)
        ->and($result['limit_orders'])->toBe(0);
});

it('refuses activation when order count is short (missing SL)', function (): void {
    $position = buildActivatableScenario(totalLimitOrders: 4);
    Order::where('position_id', $position->id)->where('type', 'STOP-MARKET')->delete();

    (new ActivatePositionJob($position->id))->compute();
})->throws(Exception::class, 'Order count mismatch: expected 7, got 6');

it('refuses activation when MARKET order is still NEW (not yet FILLED)', function (): void {
    // The polling-with-resync logic kicks in here — without a real
    // exchange to sync against, all 3 attempts fail and the throw
    // path runs. The error message reflects the post-poll state.
    $position = buildActivatableScenario();
    Order::where('position_id', $position->id)
        ->where('type', 'MARKET')
        ->update(['status' => 'NEW']);

    (new ActivatePositionJob($position->id))->compute();
})->throws(Exception::class);

it('refuses activation when a LIMIT has reference_status drift', function (): void {
    $position = buildActivatableScenario();
    $someLimit = Order::where('position_id', $position->id)->where('type', 'LIMIT')->first();
    $someLimit->update(['reference_status' => 'PARTIALLY_FILLED']);

    (new ActivatePositionJob($position->id))->compute();
})->throws(Exception::class, "reference_status is 'PARTIALLY_FILLED'");

it('refuses activation when TP price drifts from reference_price', function (): void {
    $position = buildActivatableScenario();
    $tp = Order::where('position_id', $position->id)->where('type', 'PROFIT-LIMIT')->first();
    $tp->update(['reference_price' => '0.25']); // diverged from price=0.20

    (new ActivatePositionJob($position->id))->compute();
})->throws(Exception::class, 'TP order');

it('refuses activation when there are too many LIMITs (one extra)', function (): void {
    $position = buildActivatableScenario(totalLimitOrders: 4);
    // Sneak in a 5th LIMIT bypassing the observer's slot check. Without
    // the observer, the auto-generated uuid + client_order_id never get
    // populated — set them explicitly so the insert still succeeds.
    Order::withoutEvents(function () use ($position): void {
        Order::create([
            'position_id' => $position->id,
            'uuid' => Illuminate\Support\Str::uuid()->toString(),
            'client_order_id' => Illuminate\Support\Str::uuid()->toString(),
            'side' => 'BUY',
            'position_side' => $position->direction,
            'type' => 'LIMIT',
            'price' => '0.05',
            'reference_price' => '0.05',
            'quantity' => '50',
            'reference_quantity' => '50',
            'status' => 'NEW',
            'reference_status' => 'NEW',
        ]);
    });

    (new ActivatePositionJob($position->id))->compute();
})->throws(Exception::class, 'expected 7, got 8');

it('startOrFail refuses when position is not in opening status', function (string $nonOpeningStatus): void {
    $position = Position::factory()->long()->create(['status' => $nonOpeningStatus]);

    expect((new ActivatePositionJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'active' => ['active'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
    'syncing' => ['syncing'],
]);

it('startOrFail passes when position is in opening status', function (): void {
    $position = Position::factory()->long()->create(['status' => 'opening']);

    expect((new ActivatePositionJob($position->id))->startOrFail())->toBeTrue();
});
