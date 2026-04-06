<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsBulkJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;

/**
 * Helper to create exchange symbol with unique token for bulk indicator tests
 */
function createExchangeSymbolForIndicatorBulkTest(#[SensitiveParameter] string $token, string $quoteCanonical = 'USDT'): ExchangeSymbol
{
    // Create TAAPI API system (needed by Account::admin('taapi'))
    ApiSystem::factory()->taapi()->create();

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    // Ensure Engine has TAAPI secret
    Kraite::first()->update(['taapi_secret' => 'test-secret-key']);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => $quoteCanonical,
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
    ]);
}

/**
 * Helper to seed the required indicators for tests
 */
function seedIndicatorsForTest(): void
{
    // Create non-computed (apiable) indicators
    Indicator::updateOrCreate(
        ['canonical' => 'candle-comparison'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => false,
            'class' => 'Kraite\Core\Indicators\RefreshData\CandleComparisonIndicator',
            'parameters' => ['results' => 2],
        ]
    );

    Indicator::updateOrCreate(
        ['canonical' => 'adx'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => false,
            'class' => 'Kraite\Core\Indicators\RefreshData\ADXIndicator',
            'parameters' => ['results' => 1],
        ]
    );

    Indicator::updateOrCreate(
        ['canonical' => 'ema-40'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => false,
            'class' => 'Kraite\Core\Indicators\RefreshData\EMAIndicator',
            'parameters' => ['backtrack' => 1, 'results' => 2, 'period' => '40'],
        ]
    );

    Indicator::updateOrCreate(
        ['canonical' => 'ema-80'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => false,
            'class' => 'Kraite\Core\Indicators\RefreshData\EMAIndicator',
            'parameters' => ['backtrack' => 1, 'results' => 2, 'period' => '80'],
        ]
    );

    Indicator::updateOrCreate(
        ['canonical' => 'ema-120'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => false,
            'class' => 'Kraite\Core\Indicators\RefreshData\EMAIndicator',
            'parameters' => ['backtrack' => 1, 'results' => 2, 'period' => '120'],
        ]
    );

    // Create computed indicator
    Indicator::updateOrCreate(
        ['canonical' => 'emas-same-direction'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => true,
            'class' => 'Kraite\Core\Indicators\RefreshData\EMAsSameDirection',
        ]
    );
}

/**
 * Helper to create a mock TAAPI bulk response for indicators
 */
function createMockIndicatorResponse(#[SensitiveParameter] string $token, string $exchange = 'binancefutures', string $interval = '1h'): array
{
    return [
        'data' => [
            [
                'id' => "{$exchange}_{$token}/USDT_{$interval}_candle_2_0_true",
                'indicator' => 'candle',
                'result' => [
                    'timestamp' => [1764442800, 1764446400],
                    'open' => [50000.0, 50100.0],
                    'high' => [50200.0, 50300.0],
                    'low' => [49900.0, 50000.0],
                    'close' => [50100.0, 50200.0],
                    'volume' => [1000000, 1100000],
                ],
                'errors' => [],
            ],
            [
                'id' => "{$exchange}_{$token}/USDT_{$interval}_adx_1",
                'indicator' => 'adx',
                'result' => ['value' => [25.5]],
                'errors' => [],
            ],
            [
                'id' => "{$exchange}_{$token}/USDT_{$interval}_ema_40_2_1",
                'indicator' => 'ema',
                'result' => ['value' => [50050.0, 50150.0]],
                'errors' => [],
            ],
            [
                'id' => "{$exchange}_{$token}/USDT_{$interval}_ema_80_2_1",
                'indicator' => 'ema',
                'result' => ['value' => [49950.0, 50050.0]],
                'errors' => [],
            ],
            [
                'id' => "{$exchange}_{$token}/USDT_{$interval}_ema_120_2_1",
                'indicator' => 'ema',
                'result' => ['value' => [49850.0, 49950.0]],
                'errors' => [],
            ],
        ],
    ];
}

beforeEach(function () {
    // Create steps_dispatcher groups
    StepsDispatcher::updateOrCreate(['group' => 'alpha'], ['can_dispatch' => true]);
    StepsDispatcher::updateOrCreate(['group' => 'beta'], ['can_dispatch' => true]);
});

test('stores indicator histories from TAAPI bulk API response', function () {
    seedIndicatorsForTest();
    $exchangeSymbol = createExchangeSymbolForIndicatorBulkTest('INDBULK1');
    $token = $exchangeSymbol->symbol->token;

    Http::fake([
        '*/bulk' => Http::response(createMockIndicatorResponse($token), 200),
    ]);

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol->id],
        timeframe: '1h',
        shouldCleanup: true
    );

    $result = $job->computeApiable();

    // 5 apiable indicators + 1 computed indicator = 6 stored
    expect($result['stored'])->toBe(6);
    expect($result['errors'])->toBeEmpty();
    expect($result['symbols_processed'])->toBe(1);

    // Verify indicator histories were created
    $histories = IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '1h')
        ->get();

    expect($histories)->toHaveCount(6);
});

test('creates Conclude steps for each symbol after storing indicators', function () {
    seedIndicatorsForTest();
    $exchangeSymbol = createExchangeSymbolForIndicatorBulkTest('INDBULK2');
    $token = $exchangeSymbol->symbol->token;

    Http::fake([
        '*/bulk' => Http::response(createMockIndicatorResponse($token), 200),
    ]);

    $initialStepCount = Step::count();

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol->id],
        timeframe: '1h',
        shouldCleanup: true
    );

    $job->computeApiable();

    // Should have created 1 Conclude step
    expect(Step::count())->toBe($initialStepCount + 1);

    $concludeStep = Step::where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
        ->latest('id')
        ->first();

    expect($concludeStep)->not->toBeNull();
    expect($concludeStep->arguments['exchangeSymbolId'])->toBe($exchangeSymbol->id);
    expect($concludeStep->arguments['timeframe'])->toBe('1h');
    expect($concludeStep->arguments['shouldCleanup'])->toBe(true);
});

test('handles multiple exchange symbols in single bulk request', function () {
    seedIndicatorsForTest();
    $exchangeSymbol1 = createExchangeSymbolForIndicatorBulkTest('INDBULKA');
    $token1 = $exchangeSymbol1->symbol->token;

    // Create second symbol with same api_system
    $symbol2 = Symbol::factory()->create(['token' => 'INDBULKB']);
    $exchangeSymbol2 = ExchangeSymbol::factory()->create([
        'token' => 'INDBULKB',
        'quote' => $exchangeSymbol1->quote,
        'symbol_id' => $symbol2->id,
        'api_system_id' => $exchangeSymbol1->api_system_id,
    ]);
    $token2 = $symbol2->token;

    // Mock response for both symbols
    $response1 = createMockIndicatorResponse($token1);
    $response2 = createMockIndicatorResponse($token2);
    $combinedResponse = [
        'data' => array_merge($response1['data'], $response2['data']),
    ];

    Http::fake([
        '*/bulk' => Http::response($combinedResponse, 200),
    ]);

    $initialStepCount = Step::count();

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol1->id, $exchangeSymbol2->id],
        timeframe: '1h',
        shouldCleanup: false
    );

    $result = $job->computeApiable();

    // 6 indicators per symbol × 2 symbols = 12
    expect($result['stored'])->toBe(12);
    expect($result['symbols_processed'])->toBe(2);

    // Should have created 2 Conclude steps (one per symbol)
    expect(Step::count())->toBe($initialStepCount + 2);

    // Verify each symbol got its indicator histories
    expect(IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol1->id)->count())->toBe(6);
    expect(IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol2->id)->count())->toBe(6);
});

test('assigns round-robin groups to created Conclude steps', function () {
    seedIndicatorsForTest();
    $exchangeSymbol1 = createExchangeSymbolForIndicatorBulkTest('INDGROUP1');
    $token1 = $exchangeSymbol1->symbol->token;

    $symbol2 = Symbol::factory()->create(['token' => 'INDGROUP2']);
    $exchangeSymbol2 = ExchangeSymbol::factory()->create([
        'token' => 'INDGROUP2',
        'quote' => $exchangeSymbol1->quote,
        'symbol_id' => $symbol2->id,
        'api_system_id' => $exchangeSymbol1->api_system_id,
    ]);
    $token2 = $symbol2->token;

    $response1 = createMockIndicatorResponse($token1);
    $response2 = createMockIndicatorResponse($token2);
    $combinedResponse = [
        'data' => array_merge($response1['data'], $response2['data']),
    ];

    Http::fake([
        '*/bulk' => Http::response($combinedResponse, 200),
    ]);

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol1->id, $exchangeSymbol2->id],
        timeframe: '1h',
        shouldCleanup: true
    );

    $job->computeApiable();

    // Get the created steps
    $steps = Step::where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
        ->latest('id')
        ->limit(2)
        ->get();

    // Each step should have a unique block_uuid
    $blockUuids = $steps->pluck('block_uuid')->unique();
    expect($blockUuids)->toHaveCount(2);

    // Steps should have groups assigned (either alpha or beta based on round-robin)
    $steps->each(function ($step) {
        expect($step->group)->toBeIn(['alpha', 'beta']);
    });
});

test('returns empty result when no exchange symbols provided', function () {
    seedIndicatorsForTest();

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [],
        timeframe: '1h',
        shouldCleanup: true
    );

    $result = $job->computeApiable();

    expect($result['stored'])->toBe(0);
    expect($result['errors'])->toContain('No exchange symbols found');
});

test('returns empty result when no indicators found', function () {
    // Don't seed indicators
    $exchangeSymbol = createExchangeSymbolForIndicatorBulkTest('INDNONE');

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol->id],
        timeframe: '1h',
        shouldCleanup: true
    );

    $result = $job->computeApiable();

    expect($result['stored'])->toBe(0);
    expect($result['errors'])->toContain('No indicators found');
});

test('stores correct timeframe in indicator histories', function () {
    seedIndicatorsForTest();
    $exchangeSymbol = createExchangeSymbolForIndicatorBulkTest('INDTF4H');
    $token = $exchangeSymbol->symbol->token;

    Http::fake([
        '*/bulk' => Http::response(createMockIndicatorResponse($token, 'binancefutures', '4h'), 200),
    ]);

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol->id],
        timeframe: '4h',
        shouldCleanup: true
    );

    $job->computeApiable();

    $histories = IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)->get();

    $histories->each(function ($history) {
        expect($history->timeframe)->toBe('4h');
    });
});

test('computes and stores emas-same-direction indicator', function () {
    seedIndicatorsForTest();
    $exchangeSymbol = createExchangeSymbolForIndicatorBulkTest('INDCOMP');
    $token = $exchangeSymbol->symbol->token;

    Http::fake([
        '*/bulk' => Http::response(createMockIndicatorResponse($token), 200),
    ]);

    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol->id],
        timeframe: '1h',
        shouldCleanup: true
    );

    $job->computeApiable();

    // Find the computed indicator
    $computedIndicator = Indicator::where('canonical', 'emas-same-direction')->first();

    $computedHistory = IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('indicator_id', $computedIndicator->id)
        ->first();

    expect($computedHistory)->not->toBeNull();
    // All EMAs are trending up (second value > first value), so conclusion should be LONG
    expect($computedHistory->conclusion)->toBe('LONG');
});

test('passes shouldCleanup parameter to Conclude steps', function () {
    seedIndicatorsForTest();
    $exchangeSymbol = createExchangeSymbolForIndicatorBulkTest('INDCLEAN');
    $token = $exchangeSymbol->symbol->token;

    Http::fake([
        '*/bulk' => Http::response(createMockIndicatorResponse($token), 200),
    ]);

    // Test with shouldCleanup = false
    $job = new QuerySymbolIndicatorsBulkJob(
        exchangeSymbolIds: [$exchangeSymbol->id],
        timeframe: '1h',
        shouldCleanup: false
    );

    $job->computeApiable();

    $concludeStep = Step::where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
        ->latest('id')
        ->first();

    expect($concludeStep->arguments['shouldCleanup'])->toBe(false);
});
