<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the OrderObserver creation slot guard. The guard refuses to
 * insert a SECOND active row of a singleton type — duplicate live
 * MARKET / STOP-MARKET / TP orders are the symptom that recreated
 * the BSB #109 + ETC #211 incidents. Each guard branch ships its
 * NonNotifiableException so the caller (PrepareOrderCorrectionJob,
 * SmartReplaceOrdersJob, PlacePositionTpsl) can soft-resolve.
 *
 *   - MARKET: 1 per position.
 *   - STOP-MARKET: 1 per position.
 *   - PROFIT-LIMIT / PROFIT-MARKET: 1 per position (shared "PROFIT"
 *     slot — guard message normalised to "PROFIT").
 *   - LIMIT: capped by position->total_limit_orders.
 *
 * INACTIVE_STATUSES = [CANCELLED, EXPIRED] — those rows DO NOT
 * count toward the slot. (Note: FILLED MARKET still counts as
 * "occupying" the slot in the current implementation — only
 * CANCELLED/EXPIRED frees a slot.)
 */
function makeSlotOrder(Position $position, array $attrs = []): ?Order
{
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

it('rejects a second active MARKET on the same position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class);
});

it('rejects a second active STOP-MARKET on the same position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class);
});

it('rejects a second active PROFIT-LIMIT on the same position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class);
});

it('rejects PROFIT-MARKET when a PROFIT-LIMIT already occupies the shared TP slot (unified PROFIT cap)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'PROFIT-MARKET', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class, 'PROFIT order creation blocked');
});

it('admits a new LIMIT until total_limit_orders is reached', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 3]);

    makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.09']);
    makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.08']);
    makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.07']);

    expect(fn () => makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.06']))
        ->toThrow(NonNotifiableException::class);
});

it('admits a new MARKET when the prior MARKET is CANCELLED (slot freed)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'CANCELLED']);

    $second = makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']);

    expect($second)->not->toBeNull()
        ->and($second->id)->toBeGreaterThan(0);
});

it('admits a new STOP-MARKET when the prior STOP-MARKET is EXPIRED (slot freed)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'EXPIRED']);

    $second = makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']);

    expect($second)->not->toBeNull();
});

it('admits unrelated type after a MARKET is in flight (slots are per-type)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']);

    $tp = makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']);
    $sl = makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']);
    $limit = makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.09', 'status' => 'NEW']);

    expect($tp)->not->toBeNull()
        ->and($sl)->not->toBeNull()
        ->and($limit)->not->toBeNull();
});
