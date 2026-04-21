<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

/**
 * OrderObserver's drift detector used to false-fire PrepareOrderCorrectionJob
 * during WAP's own apiSync — the profit order was moved intentionally but
 * reference values hadn't caught up yet, so price != reference_price read as
 * "someone modified this on the exchange, undo it". The observer now gates
 * drift detection to positions in the stable 'active' state so in-flight
 * workflows (waping, opening, syncing) can legitimately mutate order
 * price/quantity without tripping the self-correction alarm.
 */
function createOrderForDrift(Position $position, array $overrides = []): Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'side' => 'SELL',
        'type' => 'PROFIT-LIMIT',
        'price' => '40000.00',
        'reference_price' => '40000.00',
        'quantity' => '0.001',
        'reference_quantity' => '0.001',
        'position_side' => $position->direction,
        'status' => 'NEW',
    ], $overrides));
}

function pendingCorrectionsFor(Order $order): int
{
    return Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->count();
}

it('dispatches a correction when drift is seen on an active position', function () {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = createOrderForDrift($position);

    // Simulate an exchange-side modification: price drifted away from reference.
    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(1);
});

it('does NOT dispatch a correction when the position is waping', function () {
    // The real-world WAP flow: computeApiable runs apiModify, then apiSync
    // writes the new price into the DB — at which point reference_price is
    // still the pre-WAP value. Without the guard, this writes a spurious
    // PrepareOrderCorrectionJob step that would (best case) self-abort on
    // startOrFail once complete() fires, or (worst case) actually revert
    // our WAP modify if the dispatcher tick lands in the race window.
    $position = Position::factory()->long()->create(['status' => 'waping']);
    $order = createOrderForDrift($position);

    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(0);
});

it('does NOT dispatch a correction when the position is opening', function () {
    $position = Position::factory()->long()->create(['status' => 'opening']);
    $order = createOrderForDrift($position);

    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(0);
});

it('does NOT dispatch a correction when the position is syncing', function () {
    $position = Position::factory()->long()->create(['status' => 'syncing']);
    $order = createOrderForDrift($position);

    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(0);
});

it('does NOT dispatch a correction when the price matches reference on an active position', function () {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = createOrderForDrift($position);

    // Unrelated field bump — nothing drifted.
    $order->update(['filled_at' => now()]);

    expect(pendingCorrectionsFor($order))->toBe(0);
});

it('dispatches a correction on quantity drift on an active position', function () {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = createOrderForDrift($position);

    $order->update(['quantity' => '0.002']);

    expect(pendingCorrectionsFor($order))->toBe(1);
});
