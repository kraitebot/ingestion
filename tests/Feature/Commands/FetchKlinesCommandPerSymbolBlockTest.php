<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\ExchangeSymbol\DispatchPerSymbolKlineBlocksJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

beforeEach(function (): void {
    Step::query()->delete();

    $this->apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'is_exchange' => true,
            'name' => 'Binance',
            'recvwindow_margin' => 1000,
        ]
    );

    // Timeframes used to live per-exchange on `api_systems`; now on the
    // kraite singleton. Seed the 2-timeframe set this suite asserts over.
    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['1h', '4h']]
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

test('bulk path creates shared BTC block with orchestrator at index 2', function (): void {
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

test('bulk path does not create per-symbol correlation steps upfront — they are spawned lazily by the orchestrator', function (): void {
    $this->artisan('kraite:cron-fetch-klines', ['--canonical' => 'binance'])
        ->assertExitCode(0);

    // Before the orchestrator runs, no correlation or elasticity steps exist
    expect(Step::where('class', 'Kraite\\Core\\Jobs\\Models\\ExchangeSymbol\\CalculateBtcCorrelationJob')->count())->toBe(0);
    expect(Step::where('class', 'Kraite\\Core\\Jobs\\Models\\ExchangeSymbol\\CalculateBtcElasticityJob')->count())->toBe(0);

    // Non-BTC symbol klines also live in per-symbol blocks, not the shared block
    $nonBtcKlines = Step::where('class', FetchKlinesJob::class)
        ->where(function ($q): void {
            $q->whereJsonContains('arguments->exchangeSymbolId', $this->link->id)
                ->orWhereJsonContains('arguments->exchangeSymbolId', $this->eth->id)
                ->orWhereJsonContains('arguments->exchangeSymbolId', $this->sol->id);
        })
        ->count();

    // Lazy spawn: none exist until orchestrator executes
    expect($nonBtcKlines)->toBe(0);
});

test('orchestrator execution materializes one block per symbol with full klines+correlation pipeline', function (): void {
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

test('bulk path excludes removed symbols without positions but keeps removed symbols with open positions', function (): void {
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
    $removedBtcBaseline = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'BTC',
        'quote' => 'USDC',
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);
    Position::factory()->long()->create([
        'exchange_symbol_id' => $removedWithPosition->id,
        'parsed_trading_pair' => 'HELDUSDT',
        'status' => 'active',
    ]);

    $this->artisan('kraite:cron-fetch-klines', ['--canonical' => 'binance'])
        ->assertExitCode(0);

    $orchestrator = Step::query()
        ->where('class', DispatchPerSymbolKlineBlocksJob::class)
        ->firstOrFail();
    $targetIds = collect($orchestrator->arguments['exchangeSymbolIds'] ?? []);
    $btcIds = Step::query()
        ->where('class', FetchKlinesJob::class)
        ->get()
        ->map(fn (Step $step): mixed => data_get($step->arguments, 'exchangeSymbolId'));

    expect($targetIds)->not->toContain($removedWithoutPosition->id)
        ->and($targetIds)->toContain($removedWithPosition->id)
        ->and($btcIds)->not->toContain($removedBtcBaseline->id);
});

test('active-position path preserves a mapped Binance symbol when the position lives on another exchange', function (): void {
    $bitget = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);
    $heldOnBitget = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitget->id,
        'token' => 'CROSSHELD',
        'quote' => 'USDT',
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);
    $binanceReference = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'CROSSHELD',
        'quote' => 'USDT',
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);
    Position::factory()->long()->create([
        'exchange_symbol_id' => $heldOnBitget->id,
        'parsed_trading_pair' => 'CROSSHELDUSDT',
        'status' => 'active',
    ]);

    $this->artisan('kraite:cron-fetch-klines', ['--only-active-positions' => true])
        ->assertExitCode(0);

    $orchestratorStep = Step::query()
        ->where('class', DispatchPerSymbolKlineBlocksJob::class)
        ->firstOrFail();
    $arguments = $orchestratorStep->arguments;
    $job = new DispatchPerSymbolKlineBlocksJob(
        exchangeSymbolIds: $arguments['exchangeSymbolIds'],
        timeframes: $arguments['timeframes'],
        limit: $arguments['limit'],
        protectedExchangeSymbolIds: $arguments['protectedExchangeSymbolIds'],
    );
    $job->step = $orchestratorStep;

    expect($job->compute()['symbols_dispatched'])->toBe(1)
        ->and(collect($arguments['protectedExchangeSymbolIds'] ?? []))->toContain($binanceReference->id);
});
