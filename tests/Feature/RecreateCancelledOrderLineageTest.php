<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * F6 regression (code-review 02-P1): concurrent recreation workflows for
 * one cancelled order could both pass the SELECT-latest-replacement guard
 * and both create + place — duplicate live exchange orders. Two layers
 * now hold the one-replacement-per-cancelled-order invariant:
 *   1. the job serializes on the cancelled order row and adopts a
 *      concurrent winner's replacement instead of re-placing;
 *   2. the unique index on recreated_from_order_id refuses a second
 *      lineage row outright.
 */
function seedCancelledOrderFixture(): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'status' => 'active',
        'quantity' => '100',
        'opening_price' => '1.00',
        'total_limit_orders' => 4,
    ]);

    $cancelled = Order::create([
        'position_id' => $position->id,
        'type' => 'LIMIT',
        'status' => 'CANCELLED',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'price' => '0.95',
        'quantity' => '50',
    ]);

    return [$position, $cancelled];
}

it('refuses a second lineage row for the same cancelled order at the schema level', function (): void {
    [$position, $cancelled] = seedCancelledOrderFixture();

    $makeReplacement = function () use ($position, $cancelled): Order {
        return Order::create([
            'position_id' => $position->id,
            'type' => 'LIMIT',
            'status' => 'NEW',
            'side' => 'BUY',
            'position_side' => 'LONG',
            'price' => '0.95',
            'quantity' => '50',
            'recreated_from_order_id' => $cancelled->id,
        ]);
    };

    $makeReplacement();

    expect($makeReplacement)->toThrow(QueryException::class);
});

it('adopts a concurrent winner\'s placed replacement instead of re-placing', function (): void {
    [$position, $cancelled] = seedCancelledOrderFixture();

    $job = new RecreateCancelledOrderJob($position->id, $cancelled->id);

    // Winner lands its replacement AFTER the loser's startOrFail lookup
    // would have run (we bypass startOrFail by invoking computeApiable
    // directly with newOrder unset — the exact mid-flight interleaving).
    $winner = Order::create([
        'position_id' => $position->id,
        'type' => 'LIMIT',
        'status' => 'NEW',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'price' => '0.95',
        'quantity' => '50',
        'is_algo' => false,
        'recreated_from_order_id' => $cancelled->id,
    ]);
    $winner->update(['exchange_order_id' => '999888777']);

    $result = $job->computeApiable();

    // Adopted, never re-placed (apiPlace would have thrown — no API creds
    // in tests), and no second lineage row appeared.
    expect($result['new_order_id'])->toBe($winner->id)
        ->and($result['message'])->toContain('adopting')
        ->and(Order::where('recreated_from_order_id', $cancelled->id)->count())->toBe(1);
});
