<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\PlaceLimitOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Regression guard for LAB #107 (realised loss on 2026-04-23 14:07).
 *
 * `PlaceLimitOrderJob` placed rung 3 on the exchange successfully
 * (exchange_order_id = 1096928959, opened_at stamped), but the step
 * was later retried — likely by recover-stale or a transient
 * doubleCheck glitch — and on retry `startOrFail()` saw
 * `exchange_order_id !== null` and refused to proceed. The step went
 * Failed, cascaded through DispatchLimitOrdersJob → cancel workflow,
 * and the forced MARKET-CANCEL closed the LONG at a worse price than
 * entry.
 *
 * The job MUST match the idempotent-resume pattern already used by
 * PlaceMarketOrderJob: if the order carries an `exchange_order_id`,
 * treat that as "already placed — resume from doubleCheck / complete"
 * instead of aborting. Retries must never orphan a confirmed-placed
 * order.
 *
 * `computeApiable()` must also skip `apiPlace()` on resume so we
 * don't double-place on the exchange.
 */
function buildLimitOrderForIdempotencyTest(?string $exchangeOrderId = null): Order
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'LABTEST']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'LABTEST',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'opening',
    ]);

    return Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'NEW',
        'price' => '0.68820000',
        'quantity' => '64.00000000',
        'exchange_order_id' => $exchangeOrderId,
    ]));
}

it('allows retry when the limit order was already placed on the exchange', function (): void {
    $order = buildLimitOrderForIdempotencyTest(exchangeOrderId: '1096928959');

    $job = new PlaceLimitOrderJob($order->id, rungIndex: 3);

    expect($job->startOrFail())->toBeTrue(
        'A limit order that already carries an exchange_order_id must be '
        .'treated as "already placed, resume" — not as "fail the step"'
    );
});

it('allows first-time placement when the order has no exchange_order_id yet', function (): void {
    $order = buildLimitOrderForIdempotencyTest(exchangeOrderId: null);

    $job = new PlaceLimitOrderJob($order->id, rungIndex: 1);

    expect($job->startOrFail())->toBeTrue();
});

it('refuses to place an order that is no longer in NEW status', function (): void {
    $order = buildLimitOrderForIdempotencyTest(exchangeOrderId: null);
    $order->updateSaving(['status' => 'FILLED']);

    $job = new PlaceLimitOrderJob($order->id, rungIndex: 1);

    expect($job->startOrFail())->toBeFalse(
        'A FILLED or otherwise-terminal order must not be re-placed'
    );
});

it('guards apiPlace() inside computeApiable when the order already has an exchange_order_id', function (): void {
    $reflection = new ReflectionMethod(PlaceLimitOrderJob::class, 'computeApiable');

    $source = file_get_contents((string) $reflection->getFileName());
    $lines = explode("\n", (string) $source);
    $methodBody = implode(
        "\n",
        array_slice(
            $lines,
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1,
        ),
    );

    // computeApiable must guard the apiPlace() call so a retried step
    // doesn't double-place on the exchange. Looks specifically INSIDE
    // the method body — presence in startOrFail is not enough.
    expect($methodBody)->toMatch('/exchange_order_id/', 'computeApiable must reference exchange_order_id as a resume guard');
});
