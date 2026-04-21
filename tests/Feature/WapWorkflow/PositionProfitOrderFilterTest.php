<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Position::profitOrder() is queried during WAP and close workflows. It must
 * never return a CANCELLED or EXPIRED row — those rows only exist during the
 * correction workflow's cancel-and-recreate window, and returning a dead
 * order into CalculateWap/VerifyIfTPIsFilled would send modifies and queries
 * against an order the exchange no longer has.
 *
 * FILLED is intentionally kept so VerifyIfTPIsFilledJob can detect a just-
 * filled TP in the same sync cycle that observed a DCA fill.
 */
/**
 * OrderObserver caps active profit orders at one per position. These filter
 * tests need to place a terminal row alongside a live one to exercise the
 * "latest by id but CANCELLED/EXPIRED" scenario, which the observer would
 * block if we went through the normal creation path. Creating without
 * events is the right move here — we're constructing a fixture, not
 * exercising the enforcement path (that's covered in OrderObserverTest).
 */
function createProfitOrder(Position $position, string $status, string $type = 'PROFIT-LIMIT'): Order
{
    // OrderObserver::creating both auto-populates uuid/client_order_id AND
    // enforces the per-position active-order cap. We want to bypass only
    // the cap (to seed historical terminal rows alongside a live one), so
    // supply the UUIDs by hand and skip the observer entirely.
    return Order::withoutEvents(fn () => Order::create([
        'uuid' => (string) Str::uuid(),
        'client_order_id' => (string) Str::uuid(),
        'position_id' => $position->id,
        'side' => $position->direction === 'LONG' ? 'SELL' : 'BUY',
        'type' => $type,
        'price' => '40000.00',
        'quantity' => '0.001',
        'position_side' => $position->direction,
        'status' => $status,
    ]));
}

it('returns the latest active PROFIT-LIMIT by id (skipping earlier CANCELLED history)', function () {
    // Two active PROFIT-LIMIT rows cannot coexist — OrderObserver enforces
    // that invariant. The real-world "multiple rows" scenario is the
    // correction workflow's cancel-and-recreate: a CANCELLED historical
    // row plus a fresh NEW row. profitOrder() must return the fresh row.
    $position = Position::factory()->long()->create();

    $historical = createProfitOrder($position, 'CANCELLED');
    $live = createProfitOrder($position, 'NEW');

    expect($position->profitOrder()->id)->toBe($live->id)
        ->and($position->profitOrder()->id)->not->toBe($historical->id);
});

it('skips CANCELLED profit orders even when they are the latest row', function () {
    $position = Position::factory()->long()->create();

    $live = createProfitOrder($position, 'NEW');
    createProfitOrder($position, 'CANCELLED'); // latest by id — must be ignored

    expect($position->profitOrder()->id)->toBe($live->id);
});

it('skips EXPIRED profit orders even when they are the latest row', function () {
    $position = Position::factory()->long()->create();

    $live = createProfitOrder($position, 'NEW');
    createProfitOrder($position, 'EXPIRED');

    expect($position->profitOrder()->id)->toBe($live->id);
});

it('returns null when every profit order is CANCELLED or EXPIRED', function () {
    $position = Position::factory()->long()->create();

    createProfitOrder($position, 'CANCELLED');
    createProfitOrder($position, 'EXPIRED');

    expect($position->profitOrder())->toBeNull();
});

it('keeps FILLED profit orders so VerifyIfTPIsFilledJob can still find them', function () {
    $position = Position::factory()->long()->create();

    $filled = createProfitOrder($position, 'FILLED');

    expect($position->profitOrder()?->id)->toBe($filled->id);
});

it('accepts PROFIT-MARKET alongside PROFIT-LIMIT', function () {
    $position = Position::factory()->long()->create();

    $market = createProfitOrder($position, 'NEW', type: 'PROFIT-MARKET');

    expect($position->profitOrder()?->id)->toBe($market->id);
});
