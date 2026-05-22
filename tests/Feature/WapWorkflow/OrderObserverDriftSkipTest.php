<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * OrderObserver's drift detector used to false-fire PrepareOrderCorrectionJob
 * during WAP's own apiSync — the profit order was moved intentionally but
 * reference values hadn't caught up yet, so price != reference_price read as
 * "someone modified this on the exchange, undo it". The observer gates drift
 * detection so that in-flight workflows which mutate orders against stale
 * reference values (opening, waping) don't trip the self-correction alarm.
 *
 * `syncing` is intentionally NOT in the skip list: a sync loop only reads
 * from the exchange and mirrors values into the DB, never touching
 * reference_*. The sync is therefore the ONLY observable window for drift
 * from a third-party modification (user edits qty/price on the exchange
 * UI); skipping during sync would close that window entirely, since once
 * the DB has been written, later syncs see no dirty fields and the
 * observer never fires again.
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
    // PrepareOrderCorrectionJob is a trade-critical workflow — the
    // OrderObserver dispatches it inside a `Steps::usingPrefix('trading')`
    // scope so the row lands in `trading_steps`, not the default `steps`
    // table. Reads must scope through the same prefix or the row won't
    // be visible (the Step model's `getTable()` reads the runtime prefix
    // context to resolve which table to query).
    return Steps::usingPrefix('trading', fn (): int => Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->count());
}

it('dispatches a correction when drift is seen on an active position', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = createOrderForDrift($position);

    // Simulate an exchange-side modification: price drifted away from reference.
    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(1);
});

it('does NOT dispatch a correction when the position is waping', function (): void {
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

it('does NOT dispatch a correction when the position is opening', function (): void {
    $position = Position::factory()->long()->create(['status' => 'opening']);
    $order = createOrderForDrift($position);

    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(0);
});

it('DOES dispatch a correction when drift is observed while the position is syncing', function (): void {
    // Sync is the only window where a third-party modification is
    // observable — apiSync reads the exchange value into the DB, which
    // triggers this observer. Closing the window would mean the drift
    // is never detected: once price is written, subsequent syncs see no
    // dirty fields and the observer never fires again.
    $position = Position::factory()->long()->create(['status' => 'syncing']);
    $order = createOrderForDrift($position);

    $order->update(['price' => '40500.00']);

    expect(pendingCorrectionsFor($order))->toBe(1);
});

it('does NOT dispatch a correction when the price matches reference on an active position', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = createOrderForDrift($position);

    // Unrelated field bump — nothing drifted.
    $order->update(['filled_at' => now()]);

    expect(pendingCorrectionsFor($order))->toBe(0);
});

it('dispatches a correction on quantity drift on an active position', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $order = createOrderForDrift($position);

    $order->update(['quantity' => '0.002']);

    expect(pendingCorrectionsFor($order))->toBe(1);
});
