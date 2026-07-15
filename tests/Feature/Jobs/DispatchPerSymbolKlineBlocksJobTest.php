<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcCorrelationJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcElasticityJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\DispatchPerSymbolKlineBlocksJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

beforeEach(function (): void {
    $this->apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['is_exchange' => true, 'name' => 'Binance', 'recvwindow_margin' => 1000]
    );
});

/**
 * Build the orchestrator step row the job would normally run under. The
 * atomic compute() only reads its own `exchangeSymbolIds` / `timeframes` /
 * `limit` and never touches the step row, but HandlesStepLifecycle expects
 * the property to be set to log state transitions.
 */
function arrangeDispatchPerSymbolStep(array $exchangeSymbolIds, array $timeframes, int $limit): Step
{
    return Step::create([
        'class' => DispatchPerSymbolKlineBlocksJob::class,
        'queue' => 'indicators',
        'arguments' => [
            'exchangeSymbolIds' => $exchangeSymbolIds,
            'timeframes' => $timeframes,
            'limit' => $limit,
        ],
    ]);
}

test('creates one independent block per symbol with klines at index 1 and correlation+elasticity at index 2', function (): void {
    $symbolA = ExchangeSymbol::factory()->create(['api_system_id' => $this->apiSystem->id, 'token' => 'LINK', 'quote' => 'USDT']);
    $symbolB = ExchangeSymbol::factory()->create(['api_system_id' => $this->apiSystem->id, 'token' => 'ETH', 'quote' => 'USDT']);

    $timeframes = ['1h', '4h'];
    $step = arrangeDispatchPerSymbolStep([$symbolA->id, $symbolB->id], $timeframes, 5);

    $job = new DispatchPerSymbolKlineBlocksJob([$symbolA->id, $symbolB->id], $timeframes, 5);
    $job->step = $step;
    $result = $job->compute();

    expect($result['symbols_dispatched'])->toBe(2);

    // Child steps this orchestrator created (everything except itself)
    $createdSteps = Step::where('id', '!=', $step->id)->get();

    // 2 symbols × (2 timeframes of FetchKlines + 1 Correlation + 1 Elasticity) = 8 rows
    expect($createdSteps)->toHaveCount(8);

    // Group by block_uuid — two distinct blocks, one per symbol
    $blocks = $createdSteps->groupBy('block_uuid');
    expect($blocks)->toHaveCount(2);

    foreach ($blocks as $peers) {
        // index 1 = two FetchKlines (one per timeframe)
        $idx1 = $peers->where('index', 1);
        expect($idx1)->toHaveCount(2);
        expect($idx1->pluck('class')->unique()->all())->toBe([FetchKlinesJob::class]);

        // index 2 = correlation + elasticity for the same symbol
        $idx2 = $peers->where('index', 2);
        expect($idx2)->toHaveCount(2);
        expect($idx2->pluck('class')->sort()->values()->all())->toBe([
            CalculateBtcCorrelationJob::class,
            CalculateBtcElasticityJob::class,
        ]);

        // Every child step lands on the indicators queue
        expect($peers->pluck('queue')->unique()->all())->toBe(['indicators']);

        // Every step in a per-symbol block targets the same exchangeSymbolId
        $symbolIds = $peers->map(fn ($p) => data_get($p->arguments, 'exchangeSymbolId'))->unique();
        expect($symbolIds)->toHaveCount(1);
    }
});

test('produces zero child blocks when exchangeSymbolIds list is empty', function (): void {
    $step = arrangeDispatchPerSymbolStep([], ['1h'], 5);

    $job = new DispatchPerSymbolKlineBlocksJob([], ['1h'], 5);
    $job->step = $step;
    $result = $job->compute();

    expect($result['symbols_dispatched'])->toBe(0);
    expect(Step::where('id', '!=', $step->id)->count())->toBe(0);
});

test('accepts a queued payload created before protected symbol ids existed', function (): void {
    $symbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'LEGACY',
        'quote' => 'USDT',
    ]);
    $step = arrangeDispatchPerSymbolStep([$symbol->id], ['1h'], 5);
    $job = new DispatchPerSymbolKlineBlocksJob([$symbol->id], ['1h'], 5);

    unset($job->protectedExchangeSymbolIds);

    /** @var DispatchPerSymbolKlineBlocksJob $restoredJob */
    $restoredJob = unserialize(serialize($job));
    $restoredJob->step = $step;

    expect($restoredJob->compute()['symbols_dispatched'])->toBe(1);
});

test('revalidates monitoring while preserving warning-only and open-position symbols', function (): void {
    $live = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'LIVE',
        'quote' => 'USDT',
    ]);
    $warningOnly = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'WARNING',
        'quote' => 'USDT',
        'is_marked_for_delisting' => true,
        'delivery_at' => null,
    ]);
    $removedWithoutPosition = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'REMOVED',
        'quote' => 'USDT',
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);
    $removedWithPosition = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'HELD',
        'quote' => 'USDT',
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);
    Position::factory()->long()->create([
        'exchange_symbol_id' => $removedWithPosition->id,
        'parsed_trading_pair' => 'HELDUSDT',
        'status' => 'active',
    ]);

    $ids = [$live->id, $warningOnly->id, $removedWithoutPosition->id, $removedWithPosition->id];
    $step = arrangeDispatchPerSymbolStep($ids, ['4h'], 1);
    $job = new DispatchPerSymbolKlineBlocksJob($ids, ['4h'], 1);
    $job->step = $step;

    $result = $job->compute();

    $dispatchedIds = Step::query()
        ->where('id', '!=', $step->id)
        ->get()
        ->map(fn (Step $child): mixed => data_get($child->arguments, 'exchangeSymbolId'))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($result['symbols_dispatched'])->toBe(3)
        ->and($dispatchedIds)->toBe(collect([$live->id, $warningOnly->id, $removedWithPosition->id])->sort()->values()->all())
        ->and($dispatchedIds)->not->toContain($removedWithoutPosition->id);
});
