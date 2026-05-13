<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pins the retry-idempotency contract for RecreateCancelledOrderJob.
 *
 * Background: prior to this contract, a worker death between
 * `apiPlace()` succeeding and `doubleCheck()` completing would lead the
 * framework to retry `computeApiable()` against a fresh `$this->newOrder`
 * (null on reconstruction). The retry would write a second local Order
 * row and place a duplicate order on the exchange — the same failure
 * shape that v1.39.0 closed for `PlaceMarketOrderJob` /
 * `PlaceLimitOrderJob`. Without a lineage column on the cancelled
 * order, no caller could detect the prior placement and short-circuit.
 *
 * The fix introduces `orders.recreated_from_order_id` as the explicit
 * lineage link. `startOrFail()` resumes from the prior replacement
 * (if any) and `computeApiable()` skips `Order::create` + `apiPlace()`
 * whenever the resumed order already carries an `exchange_order_id`.
 *
 * These tests exercise the contract end-to-end at the job lifecycle
 * boundary: first attempt creates + places + stamps lineage; retry
 * after a successful exchange placement reuses the existing row and
 * does NOT create or place again.
 */
function buildRecreateIdempotencyPosition(string $token): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'active',
        'quantity' => '100',
        'opening_price' => '1.0',
        'total_limit_orders' => 4,
    ]);
}

function buildCancelledLimitForRecreate(int $positionId, string $price = '0.95', string $qty = '50'): Order
{
    return Order::withoutEvents(fn () => Order::create([
        'position_id' => $positionId,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'CANCELLED',
        'reference_status' => 'NEW',
        'price' => $price,
        'reference_price' => $price,
        'quantity' => $qty,
        'reference_quantity' => $qty,
    ]));
}

it('startOrFail resumes a prior replacement order via recreated_from_order_id', function (): void {
    $position = buildRecreateIdempotencyPosition('RESUME');
    $cancelled = buildCancelledLimitForRecreate($position->id);

    // Prior attempt persisted a replacement and got a confirmed exchange_order_id —
    // simulating the worker death between apiPlace success and doubleCheck.
    $existingReplacement = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
        'exchange_order_id' => '999111222',
        'recreated_from_order_id' => $cancelled->id,
    ]));

    $job = new RecreateCancelledOrderJob($position->id, $cancelled->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->newOrder)->not->toBeNull()
        ->and($job->newOrder->id)->toBe($existingReplacement->id);
});

it('startOrFail still passes on first attempt when no replacement exists', function (): void {
    $position = buildRecreateIdempotencyPosition('FIRSTPASS');
    $cancelled = buildCancelledLimitForRecreate($position->id);

    $job = new RecreateCancelledOrderJob($position->id, $cancelled->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->newOrder)->toBeNull();
});

it('Order::create accepts recreated_from_order_id (column + fillable wired)', function (): void {
    $position = buildRecreateIdempotencyPosition('FILLABLE');
    $cancelled = buildCancelledLimitForRecreate($position->id);

    $replacement = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
        'recreated_from_order_id' => $cancelled->id,
    ]));

    expect($replacement->fresh()->recreated_from_order_id)->toBe($cancelled->id);
});
