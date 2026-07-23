<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\Bitget\SyncLeverageBracketsJob as BitgetSyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\Bybit\SyncLeverageBracketsJob as BybitSyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\Kucoin\SyncLeverageBracketsJob as KucoinSyncLeverageBracketsJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * Regression tests for the per-symbol SyncLeverageBrackets lifecycle
 * overrides (Bitget, Bybit, KuCoin). When a symbol is flagged as delisted,
 * the lifecycle must stop scheduling per-symbol bracket fetches for it —
 * otherwise the exchange returns a "contract removed" error and the whole
 * parent step cascades as failed on every hourly refresh.
 */
function runSyncLeverageBracketsLifecycle(string $jobClass, ApiSystem $apiSystem): ?string
{
    $step = Step::create([
        'class' => $jobClass,
        'arguments' => ['apiSystemId' => $apiSystem->id],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);

    $job = new $jobClass($apiSystem->id);
    $job->step = $step;
    $job->compute();

    return $step->fresh()->child_block_uuid;
}

function createExchangeSymbolForLifecycle(ApiSystem $apiSystem, string $token, bool $delisted): ExchangeSymbol
{
    return ExchangeSymbol::factory()->create([
        'token' => $token.'_'.uniqid(),
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'is_marked_for_delisting' => $delisted,
    ]);
}

test('Bitget SyncLeverageBracketsJob skips symbols marked for delisting', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    $active = createExchangeSymbolForLifecycle($apiSystem, 'BTC', delisted: false);
    $delisted = createExchangeSymbolForLifecycle($apiSystem, 'DENT', delisted: true);

    $childBlockUuid = runSyncLeverageBracketsLifecycle(BitgetSyncLeverageBracketsJob::class, $apiSystem);

    $childIds = Step::where('block_uuid', $childBlockUuid)
        ->get()
        ->pluck('arguments.exchangeSymbolId')
        ->all();

    expect($childIds)->toContain($active->id);
    expect($childIds)->not->toContain($delisted->id);
    expect($childIds)->toHaveCount(1);
});

test('Bybit SyncLeverageBracketsJob skips symbols marked for delisting', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
    ]);

    $active = createExchangeSymbolForLifecycle($apiSystem, 'BTC', delisted: false);
    $delisted = createExchangeSymbolForLifecycle($apiSystem, 'DENT', delisted: true);

    $childBlockUuid = runSyncLeverageBracketsLifecycle(BybitSyncLeverageBracketsJob::class, $apiSystem);

    $childIds = Step::where('block_uuid', $childBlockUuid)
        ->get()
        ->pluck('arguments.exchangeSymbolId')
        ->all();

    expect($childIds)->toContain($active->id);
    expect($childIds)->not->toContain($delisted->id);
    expect($childIds)->toHaveCount(1);
});

test('KuCoin SyncLeverageBracketsJob skips symbols marked for delisting', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'kucoin',
        'name' => 'KuCoin',
    ]);

    $active = createExchangeSymbolForLifecycle($apiSystem, 'BTC', delisted: false);
    $delisted = createExchangeSymbolForLifecycle($apiSystem, 'DENT', delisted: true);

    $childBlockUuid = runSyncLeverageBracketsLifecycle(KucoinSyncLeverageBracketsJob::class, $apiSystem);

    $childIds = Step::where('block_uuid', $childBlockUuid)
        ->get()
        ->pluck('arguments.exchangeSymbolId')
        ->all();

    expect($childIds)->toContain($active->id);
    expect($childIds)->not->toContain($delisted->id);
    expect($childIds)->toHaveCount(1);
});

it('does not duplicate a per-symbol chain when two stale job instances compute', function (string $jobClass, string $canonical): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => mb_ucfirst($canonical),
    ]);

    createExchangeSymbolForLifecycle($apiSystem, 'BTC', delisted: false);
    createExchangeSymbolForLifecycle($apiSystem, 'ETH', delisted: false);

    $parent = Step::create([
        'class' => $jobClass,
        'arguments' => ['apiSystemId' => $apiSystem->id],
        'queue' => 'cronjobs',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    $first = new $jobClass($apiSystem->id);
    $first->step = Step::findOrFail($parent->id);

    $staleSecond = new $jobClass($apiSystem->id);
    $staleSecond->step = Step::findOrFail($parent->id);

    $first->compute();
    $staleSecond->compute();

    $childBlockUuid = $parent->fresh()->child_block_uuid;

    expect($childBlockUuid)->not->toBeNull()
        ->and(Step::where('block_uuid', $childBlockUuid)->count())->toBe(2);
})->with([
    'Bitget' => [BitgetSyncLeverageBracketsJob::class, 'bitget'],
    'Bybit' => [BybitSyncLeverageBracketsJob::class, 'bybit'],
    'KuCoin' => [KucoinSyncLeverageBracketsJob::class, 'kucoin'],
]);

it('uses the configured sequential batch size for every per-symbol exchange', function (string $jobClass, string $canonical): void {
    config()->set('kraite.leverage_brackets.per_symbol_batch_size', 2);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => mb_ucfirst($canonical),
    ]);

    foreach (['BTC', 'ETH', 'SOL', 'XRP', 'DOGE'] as $token) {
        createExchangeSymbolForLifecycle($apiSystem, $token, delisted: false);
    }

    $childBlockUuid = runSyncLeverageBracketsLifecycle($jobClass, $apiSystem);

    $childSteps = Step::query()
        ->where('block_uuid', $childBlockUuid)
        ->orderBy('id')
        ->get();

    expect($childSteps->pluck('index')->all())->toBe([1, 1, 2, 2, 3])
        ->and($childSteps->pluck('queue')->unique()->values()->all())->toBe(['indicators'])
        ->and($childSteps->pluck('arguments.exchangeSymbolId')->unique()->count())->toBe(5);
})->with([
    'Bitget configured batching' => [BitgetSyncLeverageBracketsJob::class, 'bitget'],
    'Bybit configured batching' => [BybitSyncLeverageBracketsJob::class, 'bybit'],
    'KuCoin configured batching' => [KucoinSyncLeverageBracketsJob::class, 'kucoin'],
]);
