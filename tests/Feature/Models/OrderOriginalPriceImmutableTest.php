<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * `original_price` and `original_quantity` are the immutable forensic
 * anchors introduced after the 2026-05-12 incident on account #1
 * (Karine, XRPUSDT, order #25). The previous implementation conflated
 * "current intent" (`reference_price`) and "first-ever placement
 * intent" — a single failed correction silently rewrote
 * `reference_price` to the user's modified value, which then became
 * the new (incorrect) anchor for every subsequent drift check.
 *
 * Contract enforced here:
 *   - `creating` hook auto-stamps `original_price = price` and
 *     `original_quantity = quantity` if the caller didn't supply them.
 *   - Once a row carries non-null originals, no `update()` may
 *     change them — the observer reverts the dirty fields back to
 *     their last-persisted values.
 *   - Backfill is allowed (NULL → value transition is the one legit
 *     post-create write path).
 */
uses(RefreshDatabase::class)->group('feature', 'order', 'original-price', 'immutable');

function makeOriginalPriceOrder(Position $position, array $attrs = []): Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '1.35480000',
        'quantity' => '46.20000000',
        'status' => 'NEW',
    ], $attrs));
}

it('stamps original_price from price on Order::create', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position);

    expect($order->original_price)->toBe('1.3548');
    expect($order->getRawOriginal('original_price'))->toBe('1.35480000');
});

it('stamps original_quantity from quantity on Order::create', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position);

    expect($order->original_quantity)->toBe('46.2');
    expect($order->getRawOriginal('original_quantity'))->toBe('46.20000000');
});

it('respects an explicit original_price provided at create time (backfill / recovery scenario)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position, [
        'price' => '1.50000000',
        'original_price' => '1.35480000',
        'original_quantity' => '46.20000000',
    ]);

    expect($order->original_price)->toBe('1.3548');
});

it('does NOT change original_price when price is updated post-creation', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position);

    $order->update(['price' => '1.30080000']);
    $order->refresh();

    expect($order->price)->toBe('1.3008');
    expect($order->original_price)->toBe('1.3548');
});

it('does NOT change original_quantity when quantity is updated post-creation', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position);

    $order->update(['quantity' => '50.00000000']);
    $order->refresh();

    expect($order->quantity)->toBe('50');
    expect($order->original_quantity)->toBe('46.2');
});

it('reverts a direct attempt to update original_price', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position);

    $order->update(['original_price' => '999.00000000']);
    $order->refresh();

    expect($order->original_price)->toBe('1.3548');
});

it('reverts a direct attempt to update original_quantity', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position);

    $order->update(['original_quantity' => '999.00000000']);
    $order->refresh();

    expect($order->original_quantity)->toBe('46.2');
});

it('allows the NULL → value transition (backfill path)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position, [
        'original_price' => null,
        'original_quantity' => null,
    ]);

    $order->refresh();

    // Backfill writes the forensic anchor for legacy rows. Because
    // the row's original_* columns are NULL, the immutability guard
    // must permit the first stamp.
    $order->update([
        'original_price' => '1.35480000',
        'original_quantity' => '46.20000000',
    ]);
    $order->refresh();

    expect($order->original_price)->toBe('1.3548');
    expect($order->original_quantity)->toBe('46.2');
});

it('does not overwrite originals when the creating hook fires for a row that already has them set explicitly', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOriginalPriceOrder($position, [
        'price' => '2.00000000',
        'original_price' => '1.10000000',
        'original_quantity' => '7.00000000',
    ]);

    expect($order->original_price)->toBe('1.1');
    expect($order->original_quantity)->toBe('7');
});
