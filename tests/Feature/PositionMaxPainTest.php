<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\ActivatePositionJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * @param  array<string, mixed>  $attributes
 */
function createMaxPainOrder(Position $position, array $attributes): Order
{
    return Order::create([
        'position_id' => $position->id,
        'side' => $position->direction === 'LONG' ? 'BUY' : 'SELL',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '1',
        'reference_price' => '1',
        'quantity' => '1',
        'reference_quantity' => '1',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        ...$attributes,
    ]);
}

test('calculates long maximum pain from the real market fill and every accepted ladder order', function (): void {
    $position = Position::factory()->long()->create([
        'parsed_trading_pair' => 'MAX-PAIN-LONG-USDT',
        'total_limit_orders' => 4,
    ]);

    createMaxPainOrder($position, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => '0.10',
        'reference_price' => '0.10',
        'quantity' => '100',
        'reference_quantity' => '100',
    ]);

    foreach ([
        ['price' => '0.09', 'quantity' => '50'],
        ['price' => '0.08', 'quantity' => '50'],
        ['price' => '0.07', 'quantity' => '50'],
        ['price' => '0.06', 'quantity' => '50'],
    ] as $order) {
        createMaxPainOrder($position, [
            'price' => $order['price'],
            'reference_price' => $order['price'],
            'quantity' => $order['quantity'],
            'reference_quantity' => $order['quantity'],
        ]);
    }

    createMaxPainOrder($position, [
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'price' => '0.05',
        'reference_price' => '0.05',
        'quantity' => '0',
        'reference_quantity' => '0',
    ]);

    expect($position->maxPain())->toBe('10.00000000');
});

test('calculates short maximum pain and ignores cancelled or rejected ladder attempts', function (): void {
    $position = Position::factory()->short()->create([
        'parsed_trading_pair' => 'MAX-PAIN-SHORT-USDT',
        'total_limit_orders' => 3,
    ]);

    createMaxPainOrder($position, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => '100',
        'reference_price' => '100',
        'quantity' => '2',
        'reference_quantity' => '2',
    ]);
    createMaxPainOrder($position, [
        'price' => '110',
        'reference_price' => '110',
        'quantity' => '3',
        'reference_quantity' => '3',
    ]);
    createMaxPainOrder($position, [
        'price' => '105',
        'reference_price' => '105',
        'quantity' => '1000',
        'reference_quantity' => '1000',
        'status' => 'CANCELLED',
        'reference_status' => 'CANCELLED',
    ]);
    createMaxPainOrder($position, [
        'price' => '115',
        'reference_price' => '115',
        'quantity' => '1000',
        'reference_quantity' => '1000',
        'status' => 'REJECTED',
        'reference_status' => 'REJECTED',
    ]);
    createMaxPainOrder($position, [
        'type' => 'STOP-MARKET',
        'side' => 'BUY',
        'price' => '130',
        'reference_price' => '130',
        'quantity' => '0',
        'reference_quantity' => '0',
    ]);

    expect($position->maxPain())->toBe('120.00000000');
});

test('returns null rather than reporting false risk when the stop or an accepted entry is incomplete', function (): void {
    $missingStop = Position::factory()->long()->create([
        'parsed_trading_pair' => 'MAX-PAIN-NO-SL-USDT',
        'total_limit_orders' => 0,
    ]);
    createMaxPainOrder($missingStop, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => '100',
        'reference_price' => '100',
        'quantity' => '2',
        'reference_quantity' => '2',
    ]);

    $incompleteEntry = Position::factory()->long()->create([
        'parsed_trading_pair' => 'MAX-PAIN-BAD-ENTRY-USDT',
        'total_limit_orders' => 0,
    ]);
    createMaxPainOrder($incompleteEntry, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => null,
        'reference_price' => null,
        'quantity' => '2',
        'reference_quantity' => '2',
    ]);
    createMaxPainOrder($incompleteEntry, [
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'price' => '80',
        'reference_price' => '80',
        'quantity' => '0',
        'reference_quantity' => '0',
    ]);

    expect($missingStop->maxPain())->toBeNull()
        ->and($incompleteEntry->maxPain())->toBeNull();
});

test('snapshots maximum pain when opening is finalized so later cancellations cannot rewrite history', function (): void {
    $position = Position::factory()->long()->create([
        'parsed_trading_pair' => 'MAX-PAIN-SNAPSHOT-USDT',
        'status' => 'opening',
        'total_limit_orders' => 1,
        'opening_price' => '100',
        'quantity' => '2',
    ]);

    createMaxPainOrder($position, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => '100',
        'reference_price' => '100',
        'quantity' => '2',
        'reference_quantity' => '2',
    ]);
    $limit = createMaxPainOrder($position, [
        'price' => '90',
        'reference_price' => '90',
        'quantity' => '4',
        'reference_quantity' => '4',
    ]);
    createMaxPainOrder($position, [
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'price' => '110',
        'reference_price' => '110',
        'quantity' => '2',
        'reference_quantity' => '2',
    ]);
    createMaxPainOrder($position, [
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'price' => '80',
        'reference_price' => '80',
        'quantity' => '0',
        'reference_quantity' => '0',
    ]);

    expect($position->max_pain)->toBeNull()
        ->and($position->status)->toBe('opening');

    $job = new ActivatePositionJob($position->id);
    expect($job->compute()['status'])->toBe('validated');
    $job->complete();
    $position->refresh();

    expect($position->max_pain)->toBe('80.00000000')
        ->and($position->status)->toBe('active');

    $limit->updateSaving([
        'status' => 'CANCELLED',
        'reference_status' => 'CANCELLED',
    ]);

    expect($position->refresh()->max_pain)->toBe('80.00000000');
});
