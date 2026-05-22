<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\ExchangeSymbol\ConfirmPriceAlignmentWithDirectionJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\CopyDirectionToOtherExchangesJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CleanupIndicatorHistoriesJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TradeConfiguration;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;

/**
 * Helper to create exchange symbol with unique token for conclude direction tests
 */
function createExchangeSymbolForConcludeTest(#[SensitiveParameter] string $token, string $quoteCanonical = 'USDT'): ExchangeSymbol
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'name' => 'Binance',
            'is_exchange' => true,
        ]
    );

    // Timeframes used to live per-exchange on `api_systems`; now on the
    // kraite singleton. Seed the exact set this suite iterates over.
    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['1m', '5m', '15m', '1h', '4h']]
    );

    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => $quoteCanonical,
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
        'is_manually_enabled' => true,
    ]);
}

/**
 * Helper to seed the required indicators for conclude tests
 */
function seedIndicatorsForConcludeTest(): void
{
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
 * Helper to create indicator history records that simulate LONG conclusion
 */
function createLongIndicatorHistories(ExchangeSymbol $exchangeSymbol, string $timeframe): void
{
    $candleIndicator = Indicator::where('canonical', 'candle-comparison')->first();
    $adxIndicator = Indicator::where('canonical', 'adx')->first();
    $emasIndicator = Indicator::where('canonical', 'emas-same-direction')->first();

    $timestamp = now()->timestamp;

    // Candle comparison (bullish - close > open)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $candleIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'timestamp' => [$timestamp - 3600, $timestamp],
            'open' => [50000.0, 50100.0],
            'high' => [50200.0, 50300.0],
            'low' => [49900.0, 50000.0],
            'close' => [50100.0, 50200.0],
            'volume' => [1000000, 1100000],
        ],
        'conclusion' => 'LONG',
    ]);

    // ADX (valid - value >= 15)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $adxIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => ['value' => [25.5]],
        'conclusion' => true,
    ]);

    // EMAs same direction (all pointing up)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $emasIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'ema-40' => ['result' => ['value' => [50050.0, 50150.0]]],
            'ema-80' => ['result' => ['value' => [49950.0, 50050.0]]],
            'ema-120' => ['result' => ['value' => [49850.0, 49950.0]]],
        ],
        'conclusion' => 'LONG',
    ]);
}

/**
 * Helper to create indicator history records that simulate SHORT conclusion
 */
function createShortIndicatorHistories(ExchangeSymbol $exchangeSymbol, string $timeframe): void
{
    $candleIndicator = Indicator::where('canonical', 'candle-comparison')->first();
    $adxIndicator = Indicator::where('canonical', 'adx')->first();
    $emasIndicator = Indicator::where('canonical', 'emas-same-direction')->first();

    $timestamp = now()->timestamp;

    // Candle comparison (bearish - close < open)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $candleIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'timestamp' => [$timestamp - 3600, $timestamp],
            'open' => [50200.0, 50100.0],
            'high' => [50300.0, 50200.0],
            'low' => [50000.0, 49900.0],
            'close' => [50100.0, 50000.0],
            'volume' => [1000000, 1100000],
        ],
        'conclusion' => 'SHORT',
    ]);

    // ADX (valid - value >= 15)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $adxIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => ['value' => [30.0]],
        'conclusion' => true,
    ]);

    // EMAs same direction (all pointing down)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $emasIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'ema-40' => ['result' => ['value' => [50150.0, 50050.0]]],
            'ema-80' => ['result' => ['value' => [50050.0, 49950.0]]],
            'ema-120' => ['result' => ['value' => [49950.0, 49850.0]]],
        ],
        'conclusion' => 'SHORT',
    ]);
}

/**
 * Helper to create indicator history with inconclusive data (mixed directions)
 * Inconclusive happens when: directions array is empty OR contains mixed LONG/SHORT
 */
function createInconclusiveIndicatorHistories(ExchangeSymbol $exchangeSymbol, string $timeframe): void
{
    $candleIndicator = Indicator::where('canonical', 'candle-comparison')->first();
    $adxIndicator = Indicator::where('canonical', 'adx')->first();
    $emasIndicator = Indicator::where('canonical', 'emas-same-direction')->first();

    $timestamp = now()->timestamp;

    // Candle comparison says LONG
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $candleIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'timestamp' => [$timestamp - 3600, $timestamp],
            'open' => [50000.0, 50100.0],
            'close' => [50100.0, 50200.0],
        ],
        'conclusion' => 'LONG',
    ]);

    // ADX (valid)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $adxIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => ['value' => [20.0]],
        'conclusion' => true,
    ]);

    // EMAs say SHORT - this creates mixed directions (LONG + SHORT = inconclusive)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $emasIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'ema-40' => ['result' => ['value' => [50150.0, 50050.0]]], // DOWN
            'ema-80' => ['result' => ['value' => [50050.0, 49950.0]]], // DOWN
            'ema-120' => ['result' => ['value' => [49950.0, 49850.0]]], // DOWN
        ],
        'conclusion' => 'SHORT', // Opposite direction = mixed signals = inconclusive
    ]);
}

/**
 * Helper to create indicator history with failed validation (low ADX)
 */
function createFailedValidationIndicatorHistories(ExchangeSymbol $exchangeSymbol, string $timeframe): void
{
    $candleIndicator = Indicator::where('canonical', 'candle-comparison')->first();
    $adxIndicator = Indicator::where('canonical', 'adx')->first();
    $emasIndicator = Indicator::where('canonical', 'emas-same-direction')->first();

    $timestamp = now()->timestamp;

    // Candle comparison
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $candleIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'timestamp' => [$timestamp - 3600, $timestamp],
            'open' => [50000.0, 50100.0],
            'close' => [50100.0, 50200.0],
        ],
        'conclusion' => 'LONG',
    ]);

    // ADX (INVALID - value < 15)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $adxIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => ['value' => [10.0]], // Below 15 threshold
        'conclusion' => false, // Validation failed
    ]);

    // EMAs same direction
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $emasIndicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'ema-40' => ['result' => ['value' => [50050.0, 50150.0]]],
            'ema-80' => ['result' => ['value' => [49950.0, 50050.0]]],
        ],
        'conclusion' => 'LONG',
    ]);
}

/**
 * Helper to create step with job instance
 */
function createStepForConcludeJob(ExchangeSymbol $exchangeSymbol, string $timeframe, array $previousConclusions = [], bool $shouldCleanup = true): Step
{
    return Step::create([
        'class' => ConcludeSymbolDirectionAtTimeframeJob::class,
        'block_uuid' => Str::uuid()->toString(),
        'group' => 'alpha',
        'index' => 1,
        'arguments' => [
            'exchangeSymbolId' => $exchangeSymbol->id,
            'timeframe' => $timeframe,
            'previousConclusions' => $previousConclusions,
            'shouldCleanup' => $shouldCleanup,
        ],
    ]);
}

/**
 * Helper to ensure default trade configuration exists
 */
function ensureDefaultTradeConfiguration(): TradeConfiguration
{
    return TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default trade configuration',
            'least_timeframe_index_to_change_indicator' => 3,
            'fast_trade_position_duration_seconds' => 3600,
            'fast_trade_position_closed_age_seconds' => 1800,
            'disable_exchange_symbol_from_negative_pnl_position' => false,
        ]
    );
}

beforeEach(function (): void {
    StepsDispatcher::updateOrCreate(['group' => 'alpha'], ['can_dispatch' => true]);
    StepsDispatcher::updateOrCreate(['group' => 'beta'], ['can_dispatch' => true]);
    ensureDefaultTradeConfiguration();
});

/*
|--------------------------------------------------------------------------
| Basic Conclusion Tests
|--------------------------------------------------------------------------
*/

test('concludes LONG direction when all indicators agree on LONG', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONLONG1');
    $step = createStepForConcludeJob($exchangeSymbol, '1h');

    createLongIndicatorHistories($exchangeSymbol, '1h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('concluded');
    expect($result['direction'])->toBe('LONG');
    expect($result['timeframe'])->toBe('1h');
    expect($result['is_change'])->toBe('first_time');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('LONG');
    expect($exchangeSymbol->indicators_timeframe)->toBe('1h');
});

test('concludes SHORT direction when all indicators agree on SHORT', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONSHORT1');
    $step = createStepForConcludeJob($exchangeSymbol, '1h');

    createShortIndicatorHistories($exchangeSymbol, '1h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('concluded');
    expect($result['direction'])->toBe('SHORT');
    expect($result['timeframe'])->toBe('1h');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('SHORT');
});

test('stores indicators_values when concluding direction', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONVALUES');
    $step = createStepForConcludeJob($exchangeSymbol, '1h');

    createLongIndicatorHistories($exchangeSymbol, '1h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;
    $job->compute();

    $exchangeSymbol->refresh();

    expect($exchangeSymbol->indicators_values)->not->toBeNull();
    // indicators_values is stored as JSON string, not cast to array
    $indicatorsValues = is_string($exchangeSymbol->indicators_values)
        ? json_decode($exchangeSymbol->indicators_values, associative: true)
        : $exchangeSymbol->indicators_values;
    expect($indicatorsValues)->toBeArray();
    expect($exchangeSymbol->indicators_synced_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Inconclusive Timeframe Tests
|--------------------------------------------------------------------------
*/

test('spawns next timeframe workflow when current timeframe is inconclusive', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONINCON1');
    $step = createStepForConcludeJob($exchangeSymbol, '1m');

    // Create inconclusive indicators (mixed EMAs)
    createInconclusiveIndicatorHistories($exchangeSymbol, '1m');

    $initialStepCount = Step::count();

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1m',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('inconclusive');
    expect($result['next_timeframe'])->toBe('5m'); // Next in list after 1m

    // Should have spawned 2 new steps: Query + Conclude (finalization steps are only created on conclude)
    expect(Step::count())->toBe($initialStepCount + 2);

    // Verify child step was created for next timeframe
    $childQueryStep = Step::where('class', QuerySymbolIndicatorsJob::class)
        ->where('arguments->timeframe', '5m')
        ->where('arguments->exchangeSymbolId', $exchangeSymbol->id)
        ->first();

    expect($childQueryStep)->not->toBeNull();
});

test('invalidates symbol when all timeframes are exhausted', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONEXHAUST');
    $exchangeSymbol->update(['direction' => 'LONG']); // Had a direction

    $step = createStepForConcludeJob($exchangeSymbol, '4h', [
        '1m' => 'INCONCLUSIVE',
        '5m' => 'INCONCLUSIVE',
        '15m' => 'INCONCLUSIVE',
        '1h' => 'INCONCLUSIVE',
    ]);

    // Create inconclusive indicators for the last timeframe (4h)
    createInconclusiveIndicatorHistories($exchangeSymbol, '4h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '4h',
        [
            '1m' => 'INCONCLUSIVE',
            '5m' => 'INCONCLUSIVE',
            '15m' => 'INCONCLUSIVE',
            '1h' => 'INCONCLUSIVE',
        ],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('not_concluded');
    expect($result['message'])->toBe('All timeframes exhausted without conclusion');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_invalid_indicator_direction)->toBeTrue();
});

test('handles inconclusive when validation indicator fails', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONVFAIL');
    $step = createStepForConcludeJob($exchangeSymbol, '1m');

    // Create indicators with failed ADX validation
    createFailedValidationIndicatorHistories($exchangeSymbol, '1m');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1m',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    // Should be inconclusive due to failed validation
    expect($result['result'])->toBe('inconclusive');
    expect($result['next_timeframe'])->toBe('5m');
});

/*
|--------------------------------------------------------------------------
| Direction Change Tests
|--------------------------------------------------------------------------
*/

test('allows direction change at minimum timeframe index', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONDIRCHG1');
    $exchangeSymbol->update(['direction' => 'LONG']); // Currently LONG

    // Create step at timeframe index 3 (1h) which meets least_timeframe_index_to_change_indicator
    $step = createStepForConcludeJob($exchangeSymbol, '1h', [
        '1m' => 'SHORT',
        '5m' => 'SHORT',
        '15m' => 'SHORT',
    ]);

    // Create SHORT indicators - direction change from LONG to SHORT
    createShortIndicatorHistories($exchangeSymbol, '1h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [
            '1m' => 'SHORT',
            '5m' => 'SHORT',
            '15m' => 'SHORT',
        ],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('concluded');
    expect($result['direction'])->toBe('SHORT');
    expect($result['is_change'])->toBe('direction_changed');
    expect($result['old_direction'])->toBe('LONG');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('SHORT');
});

test('rejects direction change with path inconsistency', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONPATHFAIL');
    $exchangeSymbol->update(['direction' => 'LONG']); // Currently LONG

    // Create step at 1h with inconsistent path (has old LONG conclusion mixed with new SHORT)
    $step = createStepForConcludeJob($exchangeSymbol, '1h', [
        '1m' => 'SHORT',
        '5m' => 'LONG', // Path inconsistency! Old direction mixed with new
        '15m' => 'SHORT',
    ]);

    // New direction would be SHORT
    createShortIndicatorHistories($exchangeSymbol, '1h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [
            '1m' => 'SHORT',
            '5m' => 'LONG', // Inconsistent!
            '15m' => 'SHORT',
        ],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('rejected');
    expect($result['reason'])->toBe('path_inconsistency');
    expect($result['old_direction'])->toBe('LONG');
    expect($result['new_direction'])->toBe('SHORT');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_early_direction_change)->toBeTrue();
});

test('disallows direction change before minimum timeframe index', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONDIREARLY');
    $exchangeSymbol->update(['direction' => 'LONG']); // Currently LONG

    // Create step at timeframe index 0 (1m) - too early for direction change
    $step = createStepForConcludeJob($exchangeSymbol, '1m', []);

    // Try to change to SHORT
    createShortIndicatorHistories($exchangeSymbol, '1m');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1m',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    // Should be treated as inconclusive (too early for direction change)
    expect($result['result'])->toBe('inconclusive');
    expect($result['next_timeframe'])->toBe('5m');

    // Direction should not have changed
    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('LONG');
});

test('allows same direction confirmation at any timeframe', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONSAMEDIR');
    $exchangeSymbol->update(['direction' => 'LONG']); // Currently LONG

    // Create step at first timeframe (1m)
    $step = createStepForConcludeJob($exchangeSymbol, '1m', []);

    // Conclude LONG (same as current) - should always be allowed
    createLongIndicatorHistories($exchangeSymbol, '1m');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1m',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('concluded');
    expect($result['direction'])->toBe('LONG');
    expect($result['is_change'])->toBe('same_direction');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('LONG');
});

/*
|--------------------------------------------------------------------------
| Step Creation Tests
|--------------------------------------------------------------------------
*/

// NOTE: Finalization steps (ConfirmPriceAlignment, CopyDirection) are created
// dynamically by createFinalizationSteps() when direction is successfully concluded.
// They are NOT created for inconclusive timeframes (avoiding wasted step records).

test('creates finalization steps after successful conclusion at first timeframe', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONCONFIRM');
    $step = createStepForConcludeJob($exchangeSymbol, '1h');

    createLongIndicatorHistories($exchangeSymbol, '1h');

    $initialConfirmStepCount = Step::where('class', ConfirmPriceAlignmentWithDirectionJob::class)->count();
    $initialCopyStepCount = Step::where('class', CopyDirectionToOtherExchangesJob::class)->count();

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;
    $result = $job->compute();

    // Successful conclusion should update the exchange symbol
    expect($result['result'])->toBe('concluded');
    expect($result['direction'])->toBe('LONG');

    // Finalization steps are created when direction is concluded
    $newConfirmStepCount = Step::where('class', ConfirmPriceAlignmentWithDirectionJob::class)->count();
    $newCopyStepCount = Step::where('class', CopyDirectionToOtherExchangesJob::class)->count();

    expect($newConfirmStepCount)->toBe($initialConfirmStepCount + 1);
    expect($newCopyStepCount)->toBe($initialCopyStepCount + 1);
});

test('does not create Cleanup step after successful conclusion at first timeframe', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONCLEAN');
    $step = createStepForConcludeJob($exchangeSymbol, '1h', [], true);

    createLongIndicatorHistories($exchangeSymbol, '1h');

    $initialCleanupCount = Step::where('class', CleanupIndicatorHistoriesJob::class)->count();

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;
    $job->compute();

    // Cleanup steps are NOT created by the job itself when concluding at first timeframe
    expect(Step::where('class', CleanupIndicatorHistoriesJob::class)->count())->toBe($initialCleanupCount);
});

test('does not create Cleanup step when shouldCleanup is false', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONNOCLEAN');
    $step = createStepForConcludeJob($exchangeSymbol, '1h', [], false);

    createLongIndicatorHistories($exchangeSymbol, '1h');

    $initialCleanupCount = Step::where('class', CleanupIndicatorHistoriesJob::class)->count();

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        false
    );
    $job->step = $step;
    $job->compute();

    expect(Step::where('class', CleanupIndicatorHistoriesJob::class)->count())->toBe($initialCleanupCount);
});

/*
|--------------------------------------------------------------------------
| Error Handling Tests
|--------------------------------------------------------------------------
*/

test('returns error when no indicator data exists', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONNODATA');
    $step = createStepForConcludeJob($exchangeSymbol, '1h');

    // Don't create any indicator histories

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('error');
    expect($result['message'])->toContain('No indicator data found');
});

test('handles invalid timeframe gracefully', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONBADTF');
    $step = createStepForConcludeJob($exchangeSymbol, 'invalid_timeframe');

    // Create indicators with invalid timeframe
    createInconclusiveIndicatorHistories($exchangeSymbol, 'invalid_timeframe');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        'invalid_timeframe',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    // Should return error for invalid timeframe
    expect($result['result'])->toBe('error');
    expect($result['message'])->toContain('Invalid timeframe');
});

/*
|--------------------------------------------------------------------------
| Path Building Tests
|--------------------------------------------------------------------------
*/

test('builds correct path string in response', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONPATH');
    $step = createStepForConcludeJob($exchangeSymbol, '15m', [
        '1m' => 'LONG',
        '5m' => 'INCONCLUSIVE',
    ]);

    createInconclusiveIndicatorHistories($exchangeSymbol, '15m');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '15m',
        [
            '1m' => 'LONG',
            '5m' => 'INCONCLUSIVE',
        ],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['path'])->toContain('1m=LONG');
    expect($result['path'])->toContain('5m=INCONCLUSIVE');
    expect($result['path'])->toContain('15m=INCONCLUSIVE');
});

/*
|--------------------------------------------------------------------------
| Multiple Timeframe Progression Tests
|--------------------------------------------------------------------------
*/

test('correctly progresses through multiple timeframes until conclusion', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONPROGRESS');

    // First timeframe (1m) - inconclusive
    $step1 = createStepForConcludeJob($exchangeSymbol, '1m', []);
    createInconclusiveIndicatorHistories($exchangeSymbol, '1m');

    $job1 = new ConcludeSymbolDirectionAtTimeframeJob($exchangeSymbol->id, '1m', [], true);
    $job1->step = $step1;
    $result1 = $job1->compute();

    expect($result1['result'])->toBe('inconclusive');
    expect($result1['next_timeframe'])->toBe('5m');

    // Clear histories for next timeframe
    IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)->delete();

    // Second timeframe (5m) - also inconclusive
    $step2 = createStepForConcludeJob($exchangeSymbol, '5m', ['1m' => 'INCONCLUSIVE']);
    createInconclusiveIndicatorHistories($exchangeSymbol, '5m');

    $job2 = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '5m',
        ['1m' => 'INCONCLUSIVE'],
        true
    );
    $job2->step = $step2;
    $result2 = $job2->compute();

    expect($result2['result'])->toBe('inconclusive');
    expect($result2['next_timeframe'])->toBe('15m');

    // Clear histories for next timeframe
    IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)->delete();

    // Third timeframe (15m) - concludes LONG
    $step3 = createStepForConcludeJob($exchangeSymbol, '15m', [
        '1m' => 'INCONCLUSIVE',
        '5m' => 'INCONCLUSIVE',
    ]);
    createLongIndicatorHistories($exchangeSymbol, '15m');

    $job3 = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '15m',
        [
            '1m' => 'INCONCLUSIVE',
            '5m' => 'INCONCLUSIVE',
        ],
        true
    );
    $job3->step = $step3;
    $result3 = $job3->compute();

    expect($result3['result'])->toBe('concluded');
    expect($result3['direction'])->toBe('LONG');
    expect($result3['timeframe'])->toBe('15m');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('LONG');
    expect($exchangeSymbol->indicators_timeframe)->toBe('15m');
});

/*
|--------------------------------------------------------------------------
| Edge Case Tests
|--------------------------------------------------------------------------
*/

test('handles symbol with null direction when concluding same direction', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONNULLDIR');
    // Direction is null by default

    $step = createStepForConcludeJob($exchangeSymbol, '1h');
    createLongIndicatorHistories($exchangeSymbol, '1h');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    expect($result['result'])->toBe('concluded');
    expect($result['is_change'])->toBe('first_time');
});

test('stamps indicators_synced_at on skip when indicator data is unchanged', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONSKIPSTAMP');

    // First conclude — populates indicators_values + stamps initial sync.
    $firstStep = createStepForConcludeJob($exchangeSymbol, '1h');
    createLongIndicatorHistories($exchangeSymbol, '1h');

    $firstJob = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $firstJob->step = $firstStep;
    $firstJob->compute();

    $exchangeSymbol->refresh();
    $firstStamp = $exchangeSymbol->indicators_synced_at;
    expect($firstStamp)->not->toBeNull();

    // Advance time so any new stamp is observable.
    $this->travel(5)->minutes();

    // Second conclude — same indicator histories, no fresh data.
    $secondStep = createStepForConcludeJob($exchangeSymbol, '1h');
    $secondJob = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $secondJob->step = $secondStep;

    $result = $secondJob->compute();

    expect($result['result'])->toBe('skipped');
    expect($result['reason'])->toBe('same_indicator_data');

    // Critical assertion: the freshness stamp must advance even on the
    // skip branch, so the system-health watchdog doesn't mistake a
    // healthy "nothing new this cycle" run for a stale-pipeline outage.
    $exchangeSymbol->refresh();
    expect($exchangeSymbol->indicators_synced_at)->not->toBeNull();
    expect($exchangeSymbol->indicators_synced_at->greaterThan($firstStamp))->toBeTrue();
});

test('handles missing indicator data for some but not all indicators', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONPARTIAL');
    $step = createStepForConcludeJob($exchangeSymbol, '1h');

    // Only create candle comparison and ADX, missing EMAs
    $candleIndicator = Indicator::where('canonical', 'candle-comparison')->first();
    $adxIndicator = Indicator::where('canonical', 'adx')->first();

    $timestamp = now()->timestamp;

    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $candleIndicator->id,
        'timeframe' => '1h',
        'timestamp' => $timestamp,
        'data' => ['timestamp' => [$timestamp - 3600, $timestamp], 'open' => [100, 101], 'close' => [101, 102]],
        'conclusion' => 'LONG',
    ]);

    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $adxIndicator->id,
        'timeframe' => '1h',
        'timestamp' => $timestamp,
        'data' => ['value' => [25.0]],
        'conclusion' => true,
    ]);

    // Missing emas-same-direction

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '1h',
        [],
        true
    );
    $job->step = $step;

    $result = $job->compute();

    // Should be inconclusive due to missing indicator
    expect($result['result'])->toBe('inconclusive');
});

test('preserves previous conclusions when spawning child workflows', function (): void {
    seedIndicatorsForConcludeTest();
    $exchangeSymbol = createExchangeSymbolForConcludeTest('CONPRESERVE');
    $step = createStepForConcludeJob($exchangeSymbol, '5m', [
        '1m' => 'LONG',
    ]);

    createInconclusiveIndicatorHistories($exchangeSymbol, '5m');

    $job = new ConcludeSymbolDirectionAtTimeframeJob(
        $exchangeSymbol->id,
        '5m',
        ['1m' => 'LONG'],
        true
    );
    $job->step = $step;
    $job->compute();

    // Find the child Conclude step
    $childConcludeStep = Step::where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
        ->where('arguments->timeframe', '15m')
        ->latest('id')
        ->first();

    expect($childConcludeStep)->not->toBeNull();

    // Verify previousConclusions are preserved
    $previousConclusions = $childConcludeStep->arguments['previousConclusions'];
    expect($previousConclusions)->toHaveKey('1m');
    expect($previousConclusions)->toHaveKey('5m');
    expect($previousConclusions['1m'])->toBe('LONG');
    expect($previousConclusions['5m'])->toBe('INCONCLUSIVE');
});
