<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\DispatchLimitOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

/**
 * F4 regression (code-review 02-P1): the ladder build previously created
 * Order rows first, then swallowed per-rung Step::create failures and
 * reported success — leaving phantom NEW orders with no placement step.
 * The build is now atomic (orders + steps in one transaction via
 * buildChildChainOnce) and idempotent (a retried orchestrator must not
 * create a second ladder). These tests pin the wiring: one step per
 * order on first run, zero duplicates on rerun.
 */
function buildLadderPositionForAtomicTest(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'ATOMLAD']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'ATOMLAD',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'percentage_gap_long' => '1.5',
        'min_price' => '0.00001',
        'max_price' => '1.0',
        'min_notional' => '5',
        'tick_size' => '0.00001',
        'price_precision' => 5,
        'quantity_precision' => 0,
        'limit_quantity_multipliers' => [2, 2, 2, 2],
        'total_limit_orders' => 4,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'quantity' => '5872',
        'opening_price' => '0.16490',
        'total_limit_orders' => 4,
    ]);
}

function runDispatchLimitOrders(Position $position, ?Step $step = null): Step
{
    $step ??= Step::create([
        'class' => DispatchLimitOrdersJob::class,
        'queue' => 'orders',
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
        'index' => 1,
    ]);

    $job = new DispatchLimitOrdersJob($position->id);
    $job->step = $step;
    $job->compute();

    return $step;
}

it('creates one placement step per ladder order, atomically', function (): void {
    $position = buildLadderPositionForAtomicTest();

    $step = runDispatchLimitOrders($position);

    $orders = Order::where('position_id', $position->id)->where('type', 'LIMIT')->get();
    $childSteps = Step::where('block_uuid', $step->fresh()->child_block_uuid)->get();

    expect($orders)->toHaveCount(4)
        ->and($childSteps)->toHaveCount(4)
        // Every order has exactly its own placement step — no orphans.
        ->and($childSteps->pluck('relatable_id')->sort()->values()->all())
        ->toBe($orders->pluck('id')->sort()->values()->all());
});

it('does not create a second ladder when the orchestrator is retried', function (): void {
    $position = buildLadderPositionForAtomicTest();

    $step = runDispatchLimitOrders($position);

    // Retry: same step recomputes (recover-stale / transient-retry shape).
    runDispatchLimitOrders($position, $step->fresh());

    expect(Order::where('position_id', $position->id)->where('type', 'LIMIT')->count())->toBe(4)
        ->and(Step::where('block_uuid', $step->fresh()->child_block_uuid)->count())->toBe(4);
});
