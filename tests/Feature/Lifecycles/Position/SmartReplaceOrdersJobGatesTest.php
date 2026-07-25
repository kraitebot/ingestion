<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Position\SmartReplaceOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

function smartReplacementPosition(string $status = 'active'): Position
{
    return Position::factory()->long()->create([
        'status' => $status,
        'total_limit_orders' => 4,
    ]);
}

function addCancelledReplacementOrder(Position $position): void
{
    Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'status' => 'CANCELLED',
        'reference_status' => 'NEW',
        'price' => '1',
        'reference_price' => '1',
        'quantity' => '10',
        'reference_quantity' => '10',
    ]);
}

it('starts when an active position still has an order to recreate', function (): void {
    $position = smartReplacementPosition();
    addCancelledReplacementOrder($position);

    expect((new SmartReplaceOrdersJob($position->id))->startOrSkip())->toBeTrue();
});

it('skips when synchronization already resolved all replacement work', function (): void {
    $position = smartReplacementPosition();

    expect((new SmartReplaceOrdersJob($position->id))->startOrSkip())->toBeFalse();
});

it('skips when the position left its active lifecycle before pickup', function (): void {
    $position = smartReplacementPosition('closing');
    addCancelledReplacementOrder($position);

    expect((new SmartReplaceOrdersJob($position->id))->startOrSkip())->toBeFalse();
});
