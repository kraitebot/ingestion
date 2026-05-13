<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * Pins the StepDispatcher prefix routing for the WAP follow-up dispatch.
 *
 * `CalculateWapAndModifyProfitOrderJob::complete()` self-dispatches an
 * `ApplyWapJob` step whenever the prior WAP run swallowed unacked LIMIT
 * fills (claimed by bulk-bumping reference_status=FILLED in the same
 * transaction). The follow-up step MUST land in `trading_steps`, not
 * the default `steps` table — the OrderObserver's dedupe queries are
 * scoped to the trading prefix, so a misplaced row would let a duplicate
 * WAP race past the gate.
 *
 * Prior to this test, routing relied on the ambient StepDispatcher
 * prefix context restored by `BaseStepJob::handle()` for the parent
 * job's lifecycle. That works in production but is fragile to future
 * framework changes. This test invokes complete() outside the
 * dispatcher loop (no ambient context) and asserts the follow-up Step
 * is explicitly scoped to `trading_steps` via an in-method
 * `Steps::usingPrefix('trading', ...)` wrapper, matching the prefix
 * discipline of every other trading-step dispatch site.
 */
function buildWapFollowUpPosition(string $token): Position
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
        'status' => 'waping',
        'quantity' => '100',
        'opening_price' => '1.0',
        'profit_percentage' => '0.35',
        'total_limit_orders' => 4,
    ]);
}

function buildProfitOrderForWap(int $positionId): Order
{
    return Order::withoutEvents(fn () => Order::create([
        'position_id' => $positionId,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.05',
        'reference_price' => '1.05',
        'quantity' => '100',
        'reference_quantity' => '100',
    ]));
}

function buildUnackedFilledLimit(int $positionId): Order
{
    return Order::withoutEvents(fn () => Order::create([
        'position_id' => $positionId,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
    ]));
}

it('WAP follow-up step lands in trading_steps, not the default steps table', function (): void {
    $position = buildWapFollowUpPosition('WAPPRX');
    $profitOrder = buildProfitOrderForWap($position->id);
    buildUnackedFilledLimit($position->id);

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    $job->complete();

    $tradingCount = Steps::usingPrefix('trading', fn (): int => (int) Step::query()
        ->where('class', ApplyWapJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count());

    $defaultCount = (int) Step::query()
        ->where('class', ApplyWapJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($tradingCount)->toBe(1)
        ->and($defaultCount)->toBe(0);
});

it('does NOT dispatch a follow-up WAP step when there are no unacked LIMIT fills', function (): void {
    $position = buildWapFollowUpPosition('WAPNONE');
    $profitOrder = buildProfitOrderForWap($position->id);
    // No unacked LIMIT — only a profit order. complete() should bulk-bump nothing.

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    $job->complete();

    $tradingCount = Steps::usingPrefix('trading', fn (): int => (int) Step::query()
        ->where('class', ApplyWapJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count());

    expect($tradingCount)->toBe(0);
});
