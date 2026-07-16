<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\Bitget\PlacePositionTpslJob;
use Kraite\Core\Jobs\Atomic\Order\DispatchLimitOrdersJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceMarketOrderJob;
use Kraite\Core\Jobs\Atomic\Position\ActivatePositionJob;
use Kraite\Core\Jobs\Atomic\Position\DetermineLeverageJob;
use Kraite\Core\Jobs\Atomic\Position\PreparePositionDataJob;
use Kraite\Core\Jobs\Atomic\Position\SetLeverageJob;
use Kraite\Core\Jobs\Atomic\Position\SetMarginModeJob;
use Kraite\Core\Jobs\Atomic\Position\VerifyOrderNotionalForMarketOrderJob;
use Kraite\Core\Jobs\Atomic\Position\VerifyTradingPairNotOpenJob;
use Kraite\Core\Jobs\Lifecycles\Position\Bitget\DispatchPositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\CancelPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

uses()->group('feature', 'bitget', 'position-opening', 'parity');

function bitgetOpeningContractPosition(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Opening Contract',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'BGOPEN']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => 'BGOPEN',
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'margin_mode' => 'isolated',
        'on_hedge_mode' => false,
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'new',
        'direction' => 'LONG',
        'total_limit_orders' => 4,
    ]);
}

it('builds the complete Bitget opening chain once with combined TP and SL protection', function (): void {
    $position = bitgetOpeningContractPosition();
    $parent = Step::create([
        'class' => DispatchPositionJob::class,
        'queue' => 'positions',
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'arguments' => ['positionId' => $position->id],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);
    $job = new DispatchPositionJob($position->id);
    $job->step = $parent;

    expect($parent->child_block_uuid)->toBeNull();

    $firstResult = $job->compute();
    $childBlockUuid = $parent->fresh()->child_block_uuid;
    $childSteps = Step::query()
        ->where('block_uuid', $childBlockUuid)
        ->orderBy('index')
        ->get();
    $defaultSteps = $childSteps->where('type', 'default');
    $resolverStep = $childSteps
        ->where('type', 'resolve-exception')
        ->sole();

    expect($firstResult)->toBe([
        'position_id' => $position->id,
        'message' => 'Position dispatching initiated',
    ])->and($defaultSteps->pluck('class')->all())->toBe([
        VerifyTradingPairNotOpenJob::class,
        SetMarginModeJob::class,
        PreparePositionDataJob::class,
        DetermineLeverageJob::class,
        SetLeverageJob::class,
        VerifyOrderNotionalForMarketOrderJob::class,
        PlaceMarketOrderJob::class,
        DispatchLimitOrdersJob::class,
        PlacePositionTpslJob::class,
        ActivatePositionJob::class,
    ])->and($defaultSteps->pluck('index')->all())->toBe(range(1, 10))
        ->and($resolverStep->class)->toBe(CancelPositionJob::class)
        ->and($resolverStep->index)->toBe(1)
        ->and($resolverStep->arguments['positionId'])->toBe($position->id)
        ->and($resolverStep->arguments['message'])->toBe('Position opening failed during dispatch workflow');

    $secondResult = $job->compute();

    expect($secondResult)->toBe([
        'position_id' => $position->id,
        'message' => 'Retry detected — child block already populated, no-op.',
    ])->and(Step::query()->where('block_uuid', $childBlockUuid)->count())->toBe(11);
});
