<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\Bitget\ModifyAlgoOrderJob;
use Kraite\Core\Jobs\Atomic\Order\CancelSingleAlgoOrderJob;
use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Order\Bitget\PrepareOrderCorrectionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * Regression guard for the Bitget drift-correction workflow.
 *
 * The base (Binance-shaped) `PrepareOrderCorrectionJob` dispatches a
 * `cancel + sync + recreate + sync` chain for `is_algo` orders. That
 * chain is wrong for Bitget `pos_profit` / `pos_loss` orders because
 * they cannot be cancelled individually via `cancel-plan-order`
 * (returns silent no-op). The Bitget override must instead dispatch a
 * `modify + sync` chain via `ModifyAlgoOrderJob`.
 *
 * For non-algo (LIMIT) orders the Bitget override must mirror the base
 * `correct + sync` chain — `apiModify` works on Bitget regular orders.
 */
function buildBitgetCorrectionFixture(string $type, bool $isAlgo): array
{
    $token = 'CORR'.mb_strtoupper(Str::random(4));

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'BitGet',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => 'active',
        'opening_price' => '0.20000000',
        'quantity' => '100.00000000',
    ]);

    $order = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => $type,
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '0.20690000',
        'quantity' => '100.00000000',
        'reference_price' => '0.20870000',
        'reference_quantity' => '100.00000000',
        'exchange_order_id' => '1432341121446961152',
        'is_algo' => $isAlgo,
    ]));

    return [
        'positionId' => $position->id,
        'orderId' => $order->id,
        'account' => $account,
    ];
}

it('dispatches modify + sync (not cancel + recreate) for Bitget algo orders', function (): void {
    $fixture = buildBitgetCorrectionFixture('PROFIT-LIMIT', isAlgo: true);

    $job = new PrepareOrderCorrectionJob($fixture['positionId'], $fixture['orderId']);
    $resolver = JobProxy::with($fixture['account']);
    $blockUuid = Str::uuid()->toString();

    $job->dispatchModifyAlgoWorkflow($resolver, $blockUuid);

    $steps = Step::query()
        ->where('block_uuid', $blockUuid)
        ->orderBy('index')
        ->get();

    expect($steps)->toHaveCount(2);

    expect($steps[0]->class)->toBe(ModifyAlgoOrderJob::class)
        ->and($steps[0]->index)->toBe(1)
        ->and($steps[0]->arguments['positionId'])->toBe($fixture['positionId'])
        ->and($steps[0]->arguments['orderId'])->toBe($fixture['orderId']);

    expect($steps[1]->class)->toBe(SyncPositionOrdersJob::class)
        ->and($steps[1]->index)->toBe(2)
        ->and($steps[1]->arguments['positionId'])->toBe($fixture['positionId']);

    // Negative assertion — the Bitget flow must never enqueue the
    // cancel+recreate pair that the base flow uses.
    $cancelStep = Step::query()
        ->where('block_uuid', $blockUuid)
        ->where('class', CancelSingleAlgoOrderJob::class)
        ->first();
    expect($cancelStep)->toBeNull(
        'Bitget algo correction must NOT dispatch CancelSingleAlgoOrderJob — '
        .'cancel-plan-order returns silent no-op for pos_profit / pos_loss orders.'
    );

    $recreateStep = Step::query()
        ->where('block_uuid', $blockUuid)
        ->where('class', RecreateCancelledOrderJob::class)
        ->first();
    expect($recreateStep)->toBeNull(
        'Bitget algo correction must NOT dispatch RecreateCancelledOrderJob — '
        .'the order is never CANCELLED so recreate would always fail startOrFail.'
    );
});

it('dispatches correct + sync (same as base) for Bitget LIMIT orders', function (): void {
    $fixture = buildBitgetCorrectionFixture('LIMIT', isAlgo: false);

    $job = new PrepareOrderCorrectionJob($fixture['positionId'], $fixture['orderId']);
    $resolver = JobProxy::with($fixture['account']);
    $blockUuid = Str::uuid()->toString();

    $job->dispatchLimitCorrectionWorkflow($resolver, $blockUuid);

    $steps = Step::query()
        ->where('block_uuid', $blockUuid)
        ->orderBy('index')
        ->get();

    expect($steps)->toHaveCount(2);

    // CorrectModifiedOrderJob has a Bitget-specific override; JobProxy
    // resolves to it for Bitget accounts. Either base or Bitget variant
    // is acceptable — what matters is that the LIMIT branch is the
    // correct/sync chain, not the modify/sync chain.
    expect($steps[0]->class)->toBeIn([
        CorrectModifiedOrderJob::class,
        Kraite\Core\Jobs\Atomic\Order\Bitget\CorrectModifiedOrderJob::class,
    ])
        ->and($steps[0]->index)->toBe(1)
        ->and($steps[0]->arguments['orderId'])->toBe($fixture['orderId']);

    expect($steps[1]->class)->toBe(SyncPositionOrdersJob::class)
        ->and($steps[1]->index)->toBe(2);

    // Negative: must not be the algo modify path.
    expect($steps[0]->class)->not->toBe(ModifyAlgoOrderJob::class);
});

it('startOrFail aborts when the order is not actually drifted', function (): void {
    $fixture = buildBitgetCorrectionFixture('PROFIT-LIMIT', isAlgo: true);

    Order::find($fixture['orderId'])->updateSaving([
        'price' => '0.20870000',
        'reference_price' => '0.20870000',
    ]);

    $job = new PrepareOrderCorrectionJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse(
        'No drift means the orchestrator must not enqueue any correction steps.'
    );
});

it('startOrFail aborts when the position is no longer active', function (): void {
    $fixture = buildBitgetCorrectionFixture('PROFIT-LIMIT', isAlgo: true);

    Position::find($fixture['positionId'])->updateSaving(['status' => 'closed']);

    $job = new PrepareOrderCorrectionJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse();
});

it('does not duplicate the correction chain when two stale parent instances compute', function (): void {
    $fixture = buildBitgetCorrectionFixture('PROFIT-LIMIT', isAlgo: true);
    $parent = Step::create([
        'class' => PrepareOrderCorrectionJob::class,
        'arguments' => [
            'positionId' => $fixture['positionId'],
            'orderId' => $fixture['orderId'],
        ],
        'queue' => 'positions',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    $first = new PrepareOrderCorrectionJob($fixture['positionId'], $fixture['orderId']);
    $first->step = Step::findOrFail($parent->id);

    $staleSecond = new PrepareOrderCorrectionJob($fixture['positionId'], $fixture['orderId']);
    $staleSecond->step = Step::findOrFail($parent->id);

    $first->compute();
    $staleSecond->compute();

    expect(Step::where('block_uuid', $parent->fresh()->child_block_uuid)->count())->toBe(2);
});
