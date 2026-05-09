<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\PlaceMarketOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the PlaceMarketOrder gate.
 *
 *   - position.status MUST be 'opening' (the cascade's entry point).
 *     A regression that admits 'active' or 'syncing' ships as a
 *     duplicate market order on a position already mid-life — real-
 *     money exposure.
 *
 *   - margin must be set (PrepareData has run). Without margin the
 *     downstream qty calculation throws InvalidArgumentException
 *     against null.
 *
 *   - On retry, the gate must REUSE the existing MARKET row instead
 *     of letting a duplicate hit the exchange. The retry path tag
 *     in startOrFail() loads the existing order onto $this->
 *     marketOrder so computeApiable can short-circuit.
 */
function buildMarketReadyPosition(array $overrides = []): Position
{
    return Position::factory()->long()->create(array_merge([
        'status' => 'opening',
        'margin' => '50.00',
        'leverage' => 20,
        'total_limit_orders' => 4,
    ], $overrides));
}

it('passes when status=opening and margin is set', function (): void {
    $position = buildMarketReadyPosition();

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeTrue();
});

it('refuses when position status is not opening (cascade entry guard)', function (string $nonOpening): void {
    $position = buildMarketReadyPosition(['status' => $nonOpening]);

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'active' => ['active'],
    'syncing' => ['syncing'],
    'closing' => ['closing'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
    'watching' => ['watching'],
    'new' => ['new'],
]);

it('refuses when margin is null (PrepareData has not run)', function (): void {
    $position = buildMarketReadyPosition(['margin' => null]);

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('on retry, loads the existing MARKET order onto $this->marketOrder (no duplicate placement)', function (): void {
    $position = buildMarketReadyPosition();

    $existing = Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'FILLED',
    ]);

    $job = new PlaceMarketOrderJob($position->id);
    $job->startOrFail();

    expect($job->marketOrder)->not->toBeNull()
        ->and($job->marketOrder->id)->toBe($existing->id);
});

it('on first run (no existing MARKET), $this->marketOrder remains null after the gate', function (): void {
    $position = buildMarketReadyPosition();

    $job = new PlaceMarketOrderJob($position->id);
    $job->startOrFail();

    expect($job->marketOrder)->toBeNull();
});
