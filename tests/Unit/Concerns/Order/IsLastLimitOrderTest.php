<?php

declare(strict_types=1);

use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin Order::isLastLimitOrder(). The ladder's deepest rung is the SL
 * anchor, the WAP fulcrum, and the activation count's keystone. A
 * regression that picks the wrong rung anchors the SL against the
 * second-to-last rung, leaving live exposure on the deepest fill —
 * the exact incident class the martingale strategy exists to prevent.
 *
 * Contract:
 *   - Compares by reference_quantity DESC, ties broken by id DESC.
 *   - position_side must match the position's direction (rejects
 *     stray opposite-side rows that shouldn't exist but might post-
 *     manual-cleanup).
 *   - Compares exchange_order_id strictly; rows without one (never
 *     placed remotely) cannot be the "last".
 */
function makeLimitForLastCheck(Position $position, array $attrs = []): Order
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

it('isLastLimitOrder returns true for the deepest-quantity LIMIT and false for shallower ones', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $rung1 = makeLimitForLastCheck($position, ['reference_quantity' => '100', 'price' => '0.09', 'exchange_order_id' => 'R1']);
    $rung2 = makeLimitForLastCheck($position, ['reference_quantity' => '200', 'price' => '0.08', 'exchange_order_id' => 'R2']);
    $rung3 = makeLimitForLastCheck($position, ['reference_quantity' => '400', 'price' => '0.07', 'exchange_order_id' => 'R3']);
    $rung4 = makeLimitForLastCheck($position, ['reference_quantity' => '800', 'price' => '0.06', 'exchange_order_id' => 'R4']);

    expect($rung4->isLastLimitOrder())->toBeTrue()
        ->and($rung3->isLastLimitOrder())->toBeFalse()
        ->and($rung2->isLastLimitOrder())->toBeFalse()
        ->and($rung1->isLastLimitOrder())->toBeFalse();
});

it('isLastLimitOrder breaks reference_quantity ties on id DESC (last-inserted wins)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $earlier = makeLimitForLastCheck($position, ['reference_quantity' => '500', 'exchange_order_id' => 'EARLY']);
    $later = makeLimitForLastCheck($position, ['reference_quantity' => '500', 'price' => '0.08', 'exchange_order_id' => 'LATE']);

    expect($later->isLastLimitOrder())->toBeTrue()
        ->and($earlier->isLastLimitOrder())->toBeFalse();
});

it('isLastLimitOrder picks deeper-by-ref_quantity over an exchange-placed shallower one', function (): void {
    // Note: the comparison key is reference_quantity DESC, NOT
    // exchange_order_id presence. A deeper-quantity rung that is not
    // yet placed on the exchange (exchange_order_id=null) still tops
    // the list — the comparator pins the ladder anchor to whatever
    // quantity floor the algorithm intends, and SL placement is
    // gated separately by the placement step's own readiness check.
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $deepest = makeLimitForLastCheck($position, ['reference_quantity' => '999', 'exchange_order_id' => null]);
    $shallower = makeLimitForLastCheck($position, ['reference_quantity' => '500', 'price' => '0.08', 'exchange_order_id' => 'PLACED']);

    expect($deepest->isLastLimitOrder())->toBeTrue()
        ->and($shallower->isLastLimitOrder())->toBeFalse();
});

it('isLastLimitOrder returns false when there are no LIMIT orders on the position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    $market = makeLimitForLastCheck($position, ['type' => 'MARKET', 'exchange_order_id' => 'M1']);

    expect($market->isLastLimitOrder())->toBeFalse();
});

it('isLastLimitOrder ignores LIMITs whose position_side does not match the position direction', function (): void {
    // Stray opposite-side LIMIT rows can show up post-manual-cleanup.
    // The getter must filter to the position's own direction.
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $longRung = makeLimitForLastCheck($position, ['position_side' => 'LONG', 'reference_quantity' => '100', 'exchange_order_id' => 'L1']);
    makeLimitForLastCheck($position, ['position_side' => 'SHORT', 'reference_quantity' => '999', 'price' => '0.08', 'exchange_order_id' => 'S1']);

    expect($longRung->isLastLimitOrder())->toBeTrue();
});
