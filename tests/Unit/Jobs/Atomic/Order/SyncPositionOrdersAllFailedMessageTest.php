<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

function buildPositionWithSingleSyncableOrder(int $exchangeOrderId): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'API3']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'API3',
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
        'direction' => 'SHORT',
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    // A non-MARKET order with an exchange_order_id is syncable. PROFIT-LIMIT
    // also sidesteps the LIMIT-count guard in OrderObserver::enforceOrderLimits.
    Order::create([
        'position_id' => $position->id,
        'type' => 'PROFIT-LIMIT',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'NEW',
        'price' => '0.30',
        'quantity' => '10',
        'exchange_order_id' => (string) $exchangeOrderId,
    ]);

    return $position;
}

it('names the failure count in the all-failed exception, not an order id', function (): void {
    // Pick an exchange_order_id that is numerically large so a buggy
    // message (interpolating the order id instead of the count) would be
    // visibly wrong: "All 9876543+ orders failed..." instead of "All 1 orders...".
    $exchangeOrderId = 9876543;
    $position = buildPositionWithSingleSyncableOrder($exchangeOrderId);

    $step = Step::create([
        'class' => SyncPositionOrdersJob::class,
        'arguments' => ['positionId' => $position->id],
        'queue' => 'positions',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    $job = new SyncPositionOrdersJob($position->id);
    $job->step = $step;
    $job->assignExceptionHandler();

    // Http::preventStrayRequests() is enabled in Pest.php's global beforeEach,
    // so the order's apiSync() will throw when it tries to reach Binance.
    // That drives every order into $failedOrders and triggers the
    // "all failed" RuntimeException whose message we are asserting.
    try {
        $job->computeApiable();
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        $message = $e->getMessage();

        expect($message)->toContain("All 1 orders failed to sync for position {$position->id}:");

        // The buggy interpolation would emit the raw exchange_order_id plus "+"
        // in the count slot — assert that shape never appears.
        expect($message)->not->toContain("All {$exchangeOrderId}+ orders failed");
    }
});
