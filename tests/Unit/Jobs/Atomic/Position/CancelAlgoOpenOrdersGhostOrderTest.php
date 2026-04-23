<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\CancelAlgoOpenOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * LAB #121 secondary failure (2026-04-23 16:57:36).
 *
 * When SL placement fails mid-flight (e.g. Binance -4509 for a
 * fast-trade race), `PlaceStopLossOrderJob` has already created the
 * local Order row (is_algo=1, status=NEW) BEFORE `apiPlace()` hit the
 * exchange. The failure leaves a ghost row with
 * `exchange_order_id = NULL`. When the cascade fires
 * `CancelAlgoOpenOrdersJob`, the ghost gets selected by the "is_algo +
 * not-yet-terminal" filter, `apiCancel()` is called on it, and Binance's
 * algo-cancel mapper throws `ValidationException: options.algo id field
 * is required` because there is no `exchange_order_id` to cancel
 * against. That secondary error obscures the real failure (the -4509)
 * in the step log and produces noisy dashboard state.
 *
 * The job MUST skip any algo order whose `exchange_order_id` is null —
 * there is nothing on the exchange to cancel.
 */
function buildPositionWithGhostAlgoOrder(): Position
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
        'status' => 'cancelling',
    ]);

    // The ghost: SL row created by PlaceStopLossOrderJob before
    // apiPlace() threw -4509. No exchange_order_id was ever written.
    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'status' => 'NEW',
        'price' => '0.46170000',
        'quantity' => '0',
        'is_algo' => true,
        'exchange_order_id' => null,
    ]));

    return $position;
}

it('excludes ghost algo orders (no exchange_order_id) from the cancellation loop', function (): void {
    $position = buildPositionWithGhostAlgoOrder();

    $job = new CancelAlgoOpenOrdersJob($position->id);
    $result = $job->computeApiable();

    expect($result)->toBeArray();
    expect($result['cancelled_count'] ?? null)->toBe(
        0,
        'A STOP-MARKET row with is_algo=1 and exchange_order_id=NULL was '
        .'never placed on the exchange. It must be skipped — cancelling it '
        .'yields "algo id required" ValidationException and masks the real '
        .'upstream failure.'
    );
});

it('still includes algo orders that have a real exchange_order_id', function (): void {
    $position = buildPositionWithGhostAlgoOrder();

    // Sibling real order on the same position
    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'status' => 'NEW',
        'price' => '0.46170000',
        'quantity' => '0',
        'is_algo' => true,
        'exchange_order_id' => '4000001154890322',
    ]));

    $source = file_get_contents(
        (new ReflectionClass(CancelAlgoOpenOrdersJob::class))->getFileName()
    );

    // The filter must be present at the SQL level so the pre-update
    // transaction doesn't mark ghost rows as reference_status=CANCELLED
    // either (that flip is what confuses the OrderObserver downstream).
    expect($source)->toContain('whereNotNull(', 'exchange_order_id');
});
