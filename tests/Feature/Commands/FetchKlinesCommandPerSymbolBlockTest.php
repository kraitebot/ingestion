<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\ExchangeSymbol\DispatchPerSymbolKlineBlocksJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

beforeEach(function () {
    Step::query()->delete();

    $this->apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'is_exchange' => true,
            'name' => 'Binance',
            'recvwindow_margin' => 1000,
            'timeframes' => ['1h', '4h'],
        ]
    );

    // BTC baseline (shared block will fetch these)
    $this->btc = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'BTC',
        'quote' => 'USDT',
    ]);

    // Three target symbols that the orchestrator should fan out into blocks
    $this->link = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'LINK',
        'quote' => 'USDT',
    ]);
    $this->eth = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'ETH',
        'quote' => 'USDT',
    ]);
    $this->sol = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'SOL',
        'quote' => 'USDT',
    ]);
});

test('bulk path creates shared BTC block with orchestrator at index 2', function () {
    $this->artisan('kraite:cron-fetch-klines', ['--canonical' => 'binance'])
        ->assertExitCode(0);

    // BTC klines live in a shared block at index 1 (one per timeframe)
    $btcKlines = Step::where('class', FetchKlinesJob::class)
        ->whereJsonContains('arguments->exchangeSymbolId', $this->btc->id)
        ->get();

    expect($btcKlines)->toHaveCount(2); // 1h + 4h
    expect($btcKlines->pluck('index')->unique()->all())->toBe([1]);
    expect($btcKlines->pluck('queue')->unique()->all())->toBe(['indicators']);

    // Orchestrator lives at index 2 of the same shared block
    $sharedBlockUuid = $btcKlines->first()->block_uuid;
    $orchestrator = Step::where('block_uuid', $sharedBlockUuid)
        ->where('class', DispatchPerSymbolKlineBlocksJob::class)
        ->first();

    expect($orchestrator)->not->toBeNull();
    expect($orchestrator->index)->toBe(2);
    expect($orchestrator->queue)->toBe('indicators');

    // Orchestrator's argument payload carries all non-BTC symbol ids
    $symbolIds = collect($orchestrator->arguments['exchangeSymbolIds'] ?? [])->sort()->values()->all();
    $expected = collect([$this->link->id, $this->eth->id, $this->sol->id])->sort()->values()->all();
    expect($symbolIds)->toBe($expected);

    expect($orchestrator->arguments['timeframes'])->toBe(['1h', '4h']);
});

test('bulk path does not create per-symbol correlation steps upfront — they are spawned lazily by the orchestrator', function () {
    $this->artisan('kraite:cron-fetch-klines', ['--canonical' => 'binance'])
        ->assertExitCode(0);

    // Before the orchestrator runs, no correlation or elasticity steps exist
    expect(Step::where('class', 'Kraite\\Core\\Jobs\\Models\\ExchangeSymbol\\CalculateBtcCorrelationJob')->count())->toBe(0);
    expect(Step::where('class', 'Kraite\\Core\\Jobs\\Models\\ExchangeSymbol\\CalculateBtcElasticityJob')->count())->toBe(0);

    // Non-BTC symbol klines also live in per-symbol blocks, not the shared block
    $nonBtcKlines = Step::where('class', FetchKlinesJob::class)
        ->where(function ($q) {
            $q->whereJsonContains('arguments->exchangeSymbolId', $this->link->id)
                ->orWhereJsonContains('arguments->exchangeSymbolId', $this->eth->id)
                ->orWhereJsonContains('arguments->exchangeSymbolId', $this->sol->id);
        })
        ->count();

    // Lazy spawn: none exist until orchestrator executes
    expect($nonBtcKlines)->toBe(0);
});

test('orchestrator execution materializes one block per symbol with full klines+correlation pipeline', function () {
    $this->artisan('kraite:cron-fetch-klines', ['--canonical' => 'binance'])
        ->assertExitCode(0);

    $orchestratorStep = Step::where('class', DispatchPerSymbolKlineBlocksJob::class)->first();
    expect($orchestratorStep)->not->toBeNull();

    $job = new DispatchPerSymbolKlineBlocksJob(
        exchangeSymbolIds: $orchestratorStep->arguments['exchangeSymbolIds'],
        timeframes: $orchestratorStep->arguments['timeframes'],
        limit: $orchestratorStep->arguments['limit'],
    );
    $job->step = $orchestratorStep;
    $result = $job->compute();

    expect($result['symbols_dispatched'])->toBe(3);

    // For each target symbol there's exactly one block with:
    //   index 1: 2 FetchKlines (1h + 4h)
    //   index 2: Correlation + Elasticity
    foreach ([$this->link, $this->eth, $this->sol] as $symbol) {
        $symbolSteps = Step::whereJsonContains('arguments->exchangeSymbolId', $symbol->id)
            ->where('class', '!=', DispatchPerSymbolKlineBlocksJob::class)
            ->get();

        $blocks = $symbolSteps->groupBy('block_uuid');
        expect($blocks)->toHaveCount(1, "{$symbol->token} should own exactly one block");

        $peers = $blocks->first();
        expect($peers->where('index', 1)->count())->toBe(2, "{$symbol->token} needs 2 index-1 FetchKlines");
        expect($peers->where('index', 2)->count())->toBe(2, "{$symbol->token} needs 2 index-2 correlation/elasticity");
        expect($peers->pluck('queue')->unique()->all())->toBe(['indicators']);
    }
});
