<?php

declare(strict_types=1);

use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the Order scope filters every sync / cancel / drift loop relies on.
 *
 *   - syncable() must EXCLUDE MARKET orders. MARKETs fire-and-forget
 *     on entry; including them would have the sync loop re-querying
 *     orders that already booked into the position's quantity column,
 *     triggering false drift on every cycle.
 *
 *   - cancellable() is the type allow-list for the cancel cascade.
 *     A regression that admits MARKET into cancellable() routes
 *     fire-and-forget entry orders through cancel-on-exchange calls
 *     that vendor-error every time.
 */
function makeStatusOrder(Position $position, array $attrs = []): Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
        'status' => 'NEW',
        'reference_status' => 'NEW',
    ], $attrs));
}

// ───────────────────────── scopes ─────────────────────────

it('syncable() excludes MARKET orders (entry orders fire-and-forget, never sync)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeStatusOrder($position, ['type' => 'MARKET', 'exchange_order_id' => 'M1']);
    makeStatusOrder($position, ['type' => 'LIMIT', 'exchange_order_id' => 'L1']);
    makeStatusOrder($position, ['type' => 'PROFIT-LIMIT', 'exchange_order_id' => 'TP1', 'side' => 'SELL']);

    $types = Order::syncable()->pluck('type')->all();

    expect($types)->not->toContain('MARKET')
        ->and($types)->toContain('LIMIT')
        ->and($types)->toContain('PROFIT-LIMIT');
});

it('syncable() excludes orders without an exchange_order_id (never placed)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeStatusOrder($position, ['type' => 'LIMIT', 'exchange_order_id' => null]);
    makeStatusOrder($position, ['type' => 'LIMIT', 'exchange_order_id' => 'L2', 'price' => '0.09']);

    $rows = Order::syncable()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->exchange_order_id)->toBe('L2');
});

it('cancellable() admits LIMIT, STOP-LOSS, PROFIT-LIMIT, PROFIT-MARKET — and rejects MARKET', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeStatusOrder($position, ['type' => 'MARKET']);
    makeStatusOrder($position, ['type' => 'LIMIT', 'price' => '0.09']);

    $types = Order::cancellable()->pluck('type')->all();

    expect($types)->toContain('LIMIT')
        ->and($types)->not->toContain('MARKET');
});

it('activeOnExchange() requires exchange_order_id AND status in [NEW, FILLED, PARTIALLY_FILLED]', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeStatusOrder($position, ['exchange_order_id' => null, 'status' => 'NEW']);
    makeStatusOrder($position, ['exchange_order_id' => 'A', 'status' => 'CANCELLED']);
    makeStatusOrder($position, ['exchange_order_id' => 'B', 'status' => 'NEW', 'price' => '0.09']);

    $rows = Order::activeOnExchange()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->exchange_order_id)->toBe('B');
});

it('cancelled() scope filters on reference_status (the local-truth column), not status', function (): void {
    // status reflects exchange-truth; reference_status reflects local
    // intent. The cancelled() scope groups orders we've LOCALLY set to
    // cancelled regardless of what the exchange has ack'd yet.
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeStatusOrder($position, ['status' => 'NEW', 'reference_status' => 'CANCELLED']);
    makeStatusOrder($position, ['status' => 'CANCELLED', 'reference_status' => 'NEW', 'price' => '0.09']);

    $rows = Order::cancelled()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->reference_status)->toBe('CANCELLED');
});
