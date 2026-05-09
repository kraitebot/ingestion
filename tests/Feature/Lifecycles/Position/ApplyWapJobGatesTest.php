<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the WAP-orchestrator gate behaviour. Two gate categories live
 * here, each with a different bucket on failure:
 *
 *   - startOrFail: real configuration bug → land in Failed. Today
 *     `profit_percentage===null` is the only one — the open workflow
 *     should always set it, so a missing value is a config bug worth
 *     paging.
 *
 *   - startOrSkip: observer-vs-worker race → land in Skipped (soft).
 *     - position status left active set → cascade close already running
 *     - profitOrder() === null → profit order cancelled mid-flight
 *
 *   The bucket distinction matters: a regression that demotes the
 *   profit_percentage check to startOrSkip silently swallows config
 *   bugs; a regression that promotes the race-skip check to
 *   startOrFail floods the Failed bucket with normal lifecycle noise.
 */
function buildPositionForWap(array $overrides = []): Position
{
    return Position::factory()->long()->create(array_merge([
        'status' => 'active',
        'profit_percentage' => '0.350',
    ], $overrides));
}

it('startOrFail returns true when profit_percentage is set', function (): void {
    $position = buildPositionForWap();

    expect((new ApplyWapJob($position->id))->startOrFail())->toBeTrue();
});

it('startOrFail returns false when profit_percentage is null (config bug → Failed)', function (): void {
    $position = buildPositionForWap(['profit_percentage' => null]);

    expect((new ApplyWapJob($position->id))->startOrFail())->toBeFalse();
});

it('startOrSkip returns false when position status has left the active set (close cascade in flight)', function (string $nonActive): void {
    $position = buildPositionForWap(['status' => $nonActive]);

    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
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

    expect((new ApplyWapJob($position->id))->startOrSkip())->toBeFalse();
})->with([
    'closing' => ['closing'],
    'cancelling' => ['cancelling'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
]);

it('startOrSkip returns false when no profit order exists (TP got cancelled mid-flight)', function (): void {
    $position = buildPositionForWap();

    expect((new ApplyWapJob($position->id))->startOrSkip())->toBeFalse();
});

it('startOrSkip returns true when status is active AND profit order exists', function (): void {
    $position = buildPositionForWap();

    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
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

    expect((new ApplyWapJob($position->id))->startOrSkip())->toBeTrue();
});

it('startOrSkip returns true on syncing status (LIMIT fill detected mid-sync is the canonical entry path)', function (): void {
    // syncing must be accepted in the active set for WAP. A regression
    // that drops syncing here ships as WAP refusing to fire whenever
    // the sync loop is the one that detected the LIMIT fill.
    $position = buildPositionForWap(['status' => 'syncing']);

    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
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

    expect((new ApplyWapJob($position->id))->startOrSkip())->toBeTrue();
});
