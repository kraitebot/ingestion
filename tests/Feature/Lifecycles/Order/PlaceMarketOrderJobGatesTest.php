<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\PlaceMarketOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Subscription;

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
    $position = Position::factory()->long()->create(array_merge([
        'status' => 'opening',
        'margin' => '50.00',
        'leverage' => 20,
        'total_limit_orders' => 4,
    ], $overrides));

    $subscription = Subscription::firstOrCreate(
        ['canonical' => 'market-order-gates'],
        ['name' => 'Market Order Gates', 'monthly_rate_usdt' => '75.0000', 'trial_days' => 7],
    );
    $position->account->user->forceFill([
        'subscription_id' => $subscription->id,
        'is_active' => true,
        'can_trade' => true,
        'wallet_balance_usdt' => '100.0000',
        'subscription_renews_at' => now()->addDays(30),
        'trial_started_at' => null,
        'subscription_paused_at' => null,
    ])->save();
    $position->account->forceFill([
        'is_active' => true,
        'can_trade' => true,
    ])->save();

    if ($subscription->max_accounts === 1) {
        $position->account->user->update(['active_account_id' => $position->account->id]);
    }

    return $position;
}

it('passes when status=opening and margin is set', function (): void {
    $position = buildMarketReadyPosition();
    $job = new PlaceMarketOrderJob($position->id);

    expect($job->startOrStop())->toBeTrue()
        ->and($job->startOrFail())->toBeTrue();
});

it('stops before placing a market entry when account trading was disabled', function (): void {
    $position = buildMarketReadyPosition();
    $position->account->forceFill(['can_trade' => false])->save();

    expect((new PlaceMarketOrderJob($position->id))->startOrStop())->toBeFalse();
});

it('continues reconciling an already placed market entry after account trading is disabled', function (): void {
    $position = buildMarketReadyPosition();
    $position->account->forceFill(['can_trade' => false])->save();
    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'already-placed-entry',
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new PlaceMarketOrderJob($position->id))->startOrStop())->toBeTrue();
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
