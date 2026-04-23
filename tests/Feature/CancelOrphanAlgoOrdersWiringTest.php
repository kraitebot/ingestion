<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\CancelOrphanAlgoOrdersJob;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\SmartReplaceOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

function buildAccountForExchange(string $canonical): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => ucfirst($canonical),
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);
}

it('resolves CancelOrphanAlgoOrdersJob to the Binance variant for a Binance account', function (): void {
    $account = buildAccountForExchange('binance');

    $resolved = JobProxy::with($account)->resolve(CancelOrphanAlgoOrdersJob::class);

    expect($resolved)->toBe(Kraite\Core\Jobs\Atomic\Order\Binance\CancelOrphanAlgoOrdersJob::class);
});

it('resolves CancelOrphanAlgoOrdersJob to the base no-op variant for non-Binance accounts', function (string $canonical): void {
    $account = buildAccountForExchange($canonical);

    $resolved = JobProxy::with($account)->resolve(CancelOrphanAlgoOrdersJob::class);

    expect($resolved)->toBe(CancelOrphanAlgoOrdersJob::class);
})->with([
    'bitget' => ['bitget'],
    'bybit' => ['bybit'],
    'kucoin' => ['kucoin'],
]);

it('SmartReplaceOrdersJob dispatches CancelOrphanAlgoOrdersJob as step 1 before recreations', function (): void {
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
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    // A cancelled STOP-MARKET that needs recreation.
    Order::create([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'CANCELLED',
        'price' => '0.42440000',
        'reference_price' => '0.42440000',
        'quantity' => '0',
        'reference_quantity' => '0',
        'reference_status' => 'NEW',
        'is_algo' => true,
        'exchange_order_id' => '4000001152477657',
    ]);

    $job = new SmartReplaceOrdersJob($position->id);

    // Load ordersToRecreate via the real gate.
    expect($job->startOrFail())->toBeTrue();

    $job->step = Step::create([
        'class' => SmartReplaceOrdersJob::class,
        'arguments' => ['positionId' => $position->id],
        'queue' => 'positions',
        'index' => 1,
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
        'child_block_uuid' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $job->compute();

    $dispatchedSteps = Step::query()
        ->where('block_uuid', $job->uuid())
        ->orderBy('index')
        ->get();

    expect($dispatchedSteps)->toHaveCount(3);

    // Step 1: orphan cleanup BEFORE recreation.
    expect($dispatchedSteps[0]->class)->toBe(
        Kraite\Core\Jobs\Atomic\Order\Binance\CancelOrphanAlgoOrdersJob::class
    );
    expect($dispatchedSteps[0]->index)->toBe(1);

    // Step 2: recreation — no Binance-specific override exists for this
    // atomic, so JobProxy falls back to the base class.
    expect($dispatchedSteps[1]->class)->toBe(RecreateCancelledOrderJob::class);
    expect($dispatchedSteps[1]->index)->toBe(2);

    // Step 3: final sync to refresh position status.
    expect($dispatchedSteps[2]->class)->toBe(SyncPositionOrdersJob::class);
    expect($dispatchedSteps[2]->index)->toBe(3);
});
