<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\SyncPositionQuantityFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Observers\OrderObserver;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;

/**
 * 2026-05-06 — Pin the partial-fill quantity-sync dispatch on the
 * OrderObserver. When a LIMIT (DCA) order transitions to PARTIALLY_FILLED,
 * the observer must dispatch a SyncPositionQuantityFromExchangeJob step
 * so positions.quantity tracks the live exchange size while the chunked
 * fill is still in flight. FILLED is unchanged — that path keeps routing
 * through ApplyWapJob (which already covers position quantity + TP qty +
 * TP price + breakeven).
 *
 * Distinct from `OrderObserverDispatchDedupeRaceTest` which pins the
 * lock+transaction shape. This pins the trigger semantics.
 */
function buildPartialFillScenario(string $exchange = 'bitget'): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $exchange,
        'name' => mb_ucfirst($exchange),
    ]);

    $symbol = Symbol::factory()->create(['token' => 'GRT']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'GRT',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $accountAttributes = ['api_system_id' => $apiSystem->id, 'on_hedge_mode' => true];

    if ($exchange === 'binance') {
        $accountAttributes['binance_api_key'] = 'TESTKEY';
        $accountAttributes['binance_api_secret'] = 'TESTSECRET';
    } elseif ($exchange === 'bitget') {
        $accountAttributes['bitget_api_key'] = 'TESTKEY';
        $accountAttributes['bitget_api_secret'] = 'TESTSECRET';
        $accountAttributes['bitget_passphrase'] = 'TESTPASS';
    }

    $account = Account::factory()->create($accountAttributes);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'GRTUSDT',
        'direction' => 'SHORT',
        'status' => 'active',
        'total_limit_orders' => 4,
        'quantity' => '487.50000000',
        'opening_price' => '0.02432000',
        'profit_percentage' => '0.500',
    ]);

    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'PROFIT-1',
        'type' => 'PROFIT-LIMIT',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0.02423000',
        'reference_price' => '0.02423000',
        'quantity' => '487.50000000',
        'reference_quantity' => '487.50000000',
        'is_algo' => false,
    ]));

    $limit = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'LIMIT-1',
        'type' => 'LIMIT',
        'side' => 'SELL',
        'position_side' => 'SHORT',
        'status' => 'PARTIALLY_FILLED',
        'reference_status' => 'NEW',
        'price' => '0.02663000',
        'reference_price' => '0.02663000',
        'quantity' => '975.00000000',
        'reference_quantity' => '975.00000000',
        'is_algo' => false,
    ]));

    return ['position' => $position, 'limit' => $limit];
}

it('dispatches SyncPositionQuantityFromExchangeJob when a LIMIT transitions to PARTIALLY_FILLED', function (): void {
    ['position' => $position, 'limit' => $limit] = buildPartialFillScenario('bitget');

    $observer = new OrderObserver;
    $observer->updated($limit);

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($count)->toBe(1);
});

it('deduplicates: a second PARTIALLY_FILLED observer fire does NOT add a duplicate step while the first is still pending', function (): void {
    ['position' => $position, 'limit' => $limit] = buildPartialFillScenario('bitget');

    $observer = new OrderObserver;
    $observer->updated($limit);
    $observer->updated($limit);

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->whereIn('state', [Pending::class, Dispatched::class])
        ->count();

    expect($count)->toBe(1);
});

it('routes LIMIT FILLED to ApplyWapJob, NOT SyncPositionQuantityFromExchangeJob', function (): void {
    ['position' => $position, 'limit' => $limit] = buildPartialFillScenario('bitget');

    Order::withoutEvents(fn () => $limit->forceFill(['status' => 'FILLED', 'reference_status' => 'NEW'])->save());

    $observer = new OrderObserver;
    $observer->updated($limit->fresh());

    $wapCount = Step::query()
        ->where('class', ApplyWapJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    $syncCount = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($wapCount)->toBe(1, 'FILLED LIMIT must route to ApplyWap path');
    expect($syncCount)->toBe(0, 'FILLED LIMIT must NOT trigger the partial-fill sync — WAP already covers it');
});

it('does not dispatch when a non-LIMIT order is PARTIALLY_FILLED', function (): void {
    ['position' => $position] = buildPartialFillScenario('bitget');

    $tp = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'TP-X',
        'type' => 'PROFIT-LIMIT',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'PARTIALLY_FILLED',
        'reference_status' => 'NEW',
        'price' => '0.024',
        'reference_price' => '0.024',
        'quantity' => '100',
        'reference_quantity' => '100',
        'is_algo' => false,
    ]));

    $observer = new OrderObserver;
    $observer->updated($tp);

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($count)->toBe(0);
});

it('does not dispatch when the position is not in an active state', function (): void {
    ['position' => $position, 'limit' => $limit] = buildPartialFillScenario('bitget');

    $position->forceFill(['status' => 'closing'])->save();

    $observer = new OrderObserver;
    $observer->updated($limit);

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($count)->toBe(0);
});

it('partial-fill dispatch site is wrapped in DB::transaction with lockForUpdate on the position row', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(OrderObserver::class))->getFileName()
    );

    expect($source)->toContain('private function dispatchSyncPositionQuantity(');

    $methodBody = (function () use ($source): string {
        $start = mb_strpos($source, 'private function dispatchSyncPositionQuantity(');
        $end = mb_strpos($source, 'private function ', $start + 1);
        if ($end === false) {
            $end = mb_strlen($source);
        }

        return mb_substr($source, $start, $end - $start);
    })();

    expect($methodBody)->toContain('DB::transaction');
    expect($methodBody)->toContain('lockForUpdate');
});
