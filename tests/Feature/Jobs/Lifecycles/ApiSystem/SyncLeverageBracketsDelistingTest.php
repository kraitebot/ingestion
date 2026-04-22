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
function runSyncLeverageBracketsLifecycle(string $jobClass, ApiSystem $apiSystem): string
{
    $childBlockUuid = (string) Str::uuid();

    $step = Step::create([
        'class' => $jobClass,
        'arguments' => ['apiSystemId' => $apiSystem->id],
        'block_uuid' => (string) Str::uuid(),
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
    ]);

    $job = new $jobClass($apiSystem->id);
    $job->step = $step;
    $job->compute();

    return $childBlockUuid;
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

test('Bitget SyncLeverageBracketsJob skips symbols marked for delisting', function () {
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

test('Bybit SyncLeverageBracketsJob skips symbols marked for delisting', function () {
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

test('KuCoin SyncLeverageBracketsJob skips symbols marked for delisting', function () {
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
