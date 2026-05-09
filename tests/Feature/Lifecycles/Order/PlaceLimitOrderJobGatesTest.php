<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\PlaceLimitOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the LAB #107 fix. PlaceLimitOrderJob startOrFail() must accept
 * BOTH first-attempt (NEW, no exchange_order_id) AND retry-after-
 * partial-place (NEW or PARTIALLY_FILLED with exchange_order_id set)
 * — refusing on the latter caused the rung-3-not-placed cascade that
 * forced-closed at a worse price than entry. Only terminal statuses
 * (FILLED, CANCELLED, EXPIRED) are correct refusals.
 *
 * The idempotent-resume in computeApiable (skip apiPlace if
 * exchange_order_id is already set) is what makes the gate safely
 * permissive — the place-step won't double-fire on retry.
 */
function makeLimitForPlaceGate(array $attrs = []): Order
{
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    return Order::create(array_merge([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'NEW',
    ], $attrs));
}

it('passes on first attempt (status=NEW, no exchange_order_id)', function (): void {
    $order = makeLimitForPlaceGate();

    expect((new PlaceLimitOrderJob($order->id, 1))->startOrFail())->toBeTrue();
});

it('PASSES on retry-after-place when status is still NEW (idempotent resume — LAB #107 fix)', function (): void {
    // The naive implementation refused this path because exchange_order_id
    // was set, but retry was a legitimate need (worker died after place
    // but before the framework hook completed). Test pins the post-fix
    // behaviour so a future "let's defensive-check exchange_order_id"
    // refactor can't reintroduce the cascade.
    $order = makeLimitForPlaceGate(['exchange_order_id' => 'L-RETRY']);

    expect((new PlaceLimitOrderJob($order->id, 1))->startOrFail())->toBeTrue();
});

it('PASSES when status=PARTIALLY_FILLED (mid-fill retry should still complete the step)', function (): void {
    $order = makeLimitForPlaceGate(['status' => 'PARTIALLY_FILLED', 'exchange_order_id' => 'L-PF']);

    expect((new PlaceLimitOrderJob($order->id, 1))->startOrFail())->toBeTrue();
});

it('refuses when the order has reached a terminal status', function (string $terminal): void {
    $order = makeLimitForPlaceGate(['status' => $terminal, 'exchange_order_id' => 'L-DONE']);

    expect((new PlaceLimitOrderJob($order->id, 1))->startOrFail())->toBeFalse();
})->with([
    'FILLED' => ['FILLED'],
    'CANCELLED' => ['CANCELLED'],
    'EXPIRED' => ['EXPIRED'],
]);
