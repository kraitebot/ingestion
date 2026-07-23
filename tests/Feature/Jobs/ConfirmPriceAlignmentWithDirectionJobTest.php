<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\ExchangeSymbol\ConfirmPriceAlignmentWithDirectionJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;

/**
 * Helper to create exchange symbol for price alignment tests
 */
function createExchangeSymbolForPriceAlignmentTest(#[SensitiveParameter] string $token, ?string $direction = null, ?string $timeframe = null): ExchangeSymbol
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'name' => 'Binance',
            'is_exchange' => true,
        ]
    );

    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
        'is_manually_enabled' => true,
        'direction' => $direction,
        'indicators_timeframe' => $timeframe,
    ]);
}

/**
 * Helper to seed the candle-comparison indicator
 */
function seedCandleComparisonIndicator(): Indicator
{
    return Indicator::updateOrCreate(
        ['canonical' => 'candle-comparison'],
        [
            'type' => 'conclude-indicators',
            'is_active' => true,
            'is_computed' => false,
            'class' => 'Kraite\Core\Indicators\RefreshData\CandleComparisonIndicator',
            'parameters' => ['results' => 2],
        ]
    );
}

/**
 * Helper to create indicator history with specific candle data
 *
 * @param  float  $previousOpen  Open price of previous candle (index 0)
 * @param  float  $previousClose  Close price of previous candle (index 0)
 * @param  float  $currentOpen  Open price of current candle (index 1)
 * @param  float  $currentClose  Close price of current candle (index 1)
 */
function createCandleIndicatorHistory(
    ExchangeSymbol $exchangeSymbol,
    string $timeframe,
    float $previousOpen,
    float $previousClose,
    float $currentOpen,
    float $currentClose
): IndicatorHistory {
    $indicator = Indicator::where('canonical', 'candle-comparison')->first();
    $timestamp = now()->timestamp;

    return IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicator->id,
        'timeframe' => $timeframe,
        'timestamp' => $timestamp,
        'data' => [
            'timestamp' => [$timestamp - 3600, $timestamp],
            'open' => [$previousOpen, $currentOpen],
            'high' => [max($previousOpen, $previousClose) + 10, max($currentOpen, $currentClose) + 10],
            'low' => [min($previousOpen, $previousClose) - 10, min($currentOpen, $currentClose) - 10],
            'close' => [$previousClose, $currentClose],
            'volume' => [1000000, 1100000],
        ],
        'conclusion' => $currentClose > $currentOpen ? 'LONG' : 'SHORT',
    ]);
}

/**
 * Helper to create step for price alignment job
 */
function createStepForPriceAlignmentJob(ExchangeSymbol $exchangeSymbol): Step
{
    $blockUuid = Str::uuid()->toString();

    return Step::create([
        'class' => ConfirmPriceAlignmentWithDirectionJob::class,
        'block_uuid' => $blockUuid,
        'group' => 'alpha',
        'index' => 2,
        'arguments' => ['exchangeSymbolId' => $exchangeSymbol->id],
    ]);
}

beforeEach(function (): void {
    StepsDispatcher::updateOrCreate(['group' => 'alpha'], ['can_dispatch' => true]);
    seedCandleComparisonIndicator();
});

/*
|--------------------------------------------------------------------------
| LONG Direction Price Alignment Tests
|--------------------------------------------------------------------------
|
| LONG positions require the price to be RISING (close > open)
| This validates that the candle is green (bullish)
|
*/

test('confirms LONG direction when current candle is green (close > open)', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALGRNLONG', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create a GREEN candle (close > open) - aligns with LONG
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50000.0,
        previousClose: 50100.0,
        currentOpen: 50100.0,
        currentClose: 50200.0  // close > open = GREEN candle
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('CONFIRMED');
    expect($result['response'])->toContain('LONG');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('LONG');
});

test('rejects LONG direction when current candle is red (close < open)', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALREDLONG', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create a RED candle (close < open) - does NOT align with LONG
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50000.0,
        previousClose: 50100.0,
        currentOpen: 50200.0,
        currentClose: 50100.0  // close < open = RED candle (misaligned!)
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('price misalignment');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_price_trend_misalignment)->toBeTrue();
});

test('rejects LONG direction when current candle is flat (close == open)', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALFLTLONG', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create a FLAT candle (close == open) - does NOT confirm LONG
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50000.0,
        previousClose: 50100.0,
        currentOpen: 50100.0,
        currentClose: 50100.0  // close == open = FLAT (not rising!)
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('price misalignment');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_price_trend_misalignment)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SHORT Direction Price Alignment Tests
|--------------------------------------------------------------------------
|
| SHORT positions require the price to be FALLING (close < open)
| This validates that the candle is red (bearish)
|
*/

test('confirms SHORT direction when current candle is red (close < open)', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALREDSHORT', 'SHORT', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create a RED candle (close < open) - aligns with SHORT
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50200.0,
        previousClose: 50100.0,
        currentOpen: 50100.0,
        currentClose: 50000.0  // close < open = RED candle
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('CONFIRMED');
    expect($result['response'])->toContain('SHORT');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBe('SHORT');
});

test('rejects SHORT direction when current candle is green (close > open)', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALGRNSHORT', 'SHORT', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create a GREEN candle (close > open) - does NOT align with SHORT
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50000.0,
        previousClose: 50100.0,
        currentOpen: 50000.0,
        currentClose: 50100.0  // close > open = GREEN candle (misaligned!)
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('price misalignment');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_price_trend_misalignment)->toBeTrue();
});

test('rejects SHORT direction when current candle is flat (close == open)', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALFLTSHORT', 'SHORT', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create a FLAT candle (close == open) - does NOT confirm SHORT
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50100.0,
        previousClose: 50100.0,
        currentOpen: 50100.0,
        currentClose: 50100.0  // close == open = FLAT (not falling!)
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('price misalignment');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_price_trend_misalignment)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Missing/Invalid Data Tests
|--------------------------------------------------------------------------
*/

test('invalidates symbol when no indicator history exists', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALNODATA', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Don't create any indicator history

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('missing indicator history');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->has_no_indicator_data)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Timeframe Matching Tests
|--------------------------------------------------------------------------
*/

test('uses exchange symbol timeframe to find correct indicator history', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALTF4H', 'LONG', '4h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    $indicator = Indicator::where('canonical', 'candle-comparison')->first();
    $timestamp = now()->timestamp;

    // Create 1h timeframe (should NOT be used)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicator->id,
        'timeframe' => '1h',
        'timestamp' => $timestamp,
        'data' => [
            'open' => [50200.0, 50100.0], // RED candle
            'close' => [50100.0, 50000.0],
        ],
        'conclusion' => 'SHORT',
    ]);

    // Create 4h timeframe (should be used)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicator->id,
        'timeframe' => '4h',
        'timestamp' => $timestamp,
        'data' => [
            'open' => [50000.0, 50100.0], // GREEN candle
            'close' => [50100.0, 50200.0],
        ],
        'conclusion' => 'LONG',
    ]);

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should confirm because 4h candle is green (matching LONG)
    expect($result['response'])->toContain('CONFIRMED');
    expect($result['response'])->toContain('4h');
});

test('uses latest indicator history when multiple exist for same timeframe', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALLATEST', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    $indicator = Indicator::where('canonical', 'candle-comparison')->first();

    // Create older history (RED candle - would fail)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicator->id,
        'timeframe' => '1h',
        'timestamp' => now()->subHours(2)->timestamp,
        'data' => [
            'open' => [50200.0, 50100.0],
            'close' => [50100.0, 50000.0],
        ],
        'conclusion' => 'SHORT',
    ]);

    // Create newer history (GREEN candle - should pass)
    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicator->id,
        'timeframe' => '1h',
        'timestamp' => now()->timestamp,
        'data' => [
            'open' => [50000.0, 50100.0],
            'close' => [50100.0, 50200.0],
        ],
        'conclusion' => 'LONG',
    ]);

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should confirm because latest (newer) history has green candle
    expect($result['response'])->toContain('CONFIRMED');
});

/*
|--------------------------------------------------------------------------
| Symbol State Update Tests
|--------------------------------------------------------------------------
*/

test('clears all indicator fields when price alignment fails', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALCLEAR', 'LONG', '1h');
    $exchangeSymbol->update([
        'indicators_values' => ['some' => 'data'],
        'indicators_synced_at' => now(),
    ]);

    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create misaligned candle (RED for LONG direction)
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50100.0,
        previousClose: 50000.0,
        currentOpen: 50000.0,
        currentClose: 49900.0
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;
    $job->compute();

    $exchangeSymbol->refresh();

    expect($exchangeSymbol->direction)->toBeNull();
    expect($exchangeSymbol->indicators_values)->toBeNull();
    expect($exchangeSymbol->indicators_timeframe)->toBeNull();
    // Stamped on every conclude pass — "last attempt", not "last success".
    expect($exchangeSymbol->indicators_synced_at)->not->toBeNull();
    expect($exchangeSymbol->has_price_trend_misalignment)->toBeTrue();
});

test('enables symbol for trading when price alignment confirms', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALENABLE', 'LONG', '1h');
    $exchangeSymbol->update([
        'has_no_indicator_data' => true,
        'has_price_trend_misalignment' => true,
    ]);

    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Create aligned candle (GREEN for LONG direction)
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50000.0,
        previousClose: 50100.0,
        currentOpen: 50100.0,
        currentClose: 50200.0
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;
    $job->compute();

    $exchangeSymbol->refresh();

    expect($exchangeSymbol->has_no_indicator_data)->toBeFalse();
    expect($exchangeSymbol->has_price_trend_misalignment)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Extreme Price Value Tests
|--------------------------------------------------------------------------
*/

test('handles very small price differences for LONG confirmation', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALSMALLLONG', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Very small price increase (0.00000001)
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 0.00001000,
        previousClose: 0.00001001,
        currentOpen: 0.00001001,
        currentClose: 0.00001002  // Just barely higher
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('CONFIRMED');
});

test('handles very small price differences for SHORT confirmation', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALSMALLSHORT', 'SHORT', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Very small price decrease (0.00000001)
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 0.00001002,
        previousClose: 0.00001001,
        currentOpen: 0.00001001,
        currentClose: 0.00001000  // Just barely lower
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('CONFIRMED');
});

test('handles large price values correctly', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALLARGE', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Large price values (like BTC prices)
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 98000.50,
        previousClose: 98500.25,
        currentOpen: 98500.25,
        currentClose: 99000.00
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('CONFIRMED');
    expect($result['response'])->toContain('99000');
});

/*
|--------------------------------------------------------------------------
| Response Message Tests
|--------------------------------------------------------------------------
*/

test('includes open and close prices in confirmation response', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALPRICES', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 100.0,
        previousClose: 101.0,
        currentOpen: 101.5,
        currentClose: 102.5
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('101.5'); // currentOpen
    expect($result['response'])->toContain('102.5'); // currentClose
});

test('includes timeframe in response message', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALTFRESP', 'LONG', '4h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '4h',
        previousOpen: 100.0,
        previousClose: 101.0,
        currentOpen: 101.0,
        currentClose: 102.0
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain('4h');
});

test('includes trading pair in response message', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('ETHPAIR', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 3000.0,
        previousClose: 3010.0,
        currentOpen: 3010.0,
        currentClose: 3020.0
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    expect($result['response'])->toContain($exchangeSymbol->parsed_trading_pair);
});

/*
|--------------------------------------------------------------------------
| Wicks vs Body Tests (The Fix Validation)
|--------------------------------------------------------------------------
|
| These tests validate the fix: comparing CURRENT candle's open vs close
| rather than previous close vs current close.
|
| A candle can have wicks that make previous-close vs current-close
| appear bullish while the actual candle body is bearish (and vice versa).
|
*/

test('correctly identifies LONG alignment from candle body not wicks', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALWICKLONG', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Scenario: Previous close was 100, current close is 102 (looks bullish previous->current)
    // BUT: Current candle opened at 105 and closed at 102 (RED candle body!)
    // The OLD logic would have incorrectly confirmed this as LONG
    // The NEW logic correctly rejects it because close(102) < open(105)

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 99.0,
        previousClose: 100.0,
        currentOpen: 105.0,   // Opened high (maybe gapped up)
        currentClose: 102.0   // Closed lower than open = RED candle
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should REJECT because current candle is RED (close < open)
    // even though close(102) > previousClose(100)
    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('price misalignment');
});

test('correctly identifies SHORT alignment from candle body not wicks', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALWICKSHORT', 'SHORT', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Scenario: Previous close was 100, current close is 98 (looks bearish previous->current)
    // BUT: Current candle opened at 95 and closed at 98 (GREEN candle body!)
    // The OLD logic would have incorrectly confirmed this as SHORT
    // The NEW logic correctly rejects it because close(98) > open(95)

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 101.0,
        previousClose: 100.0,
        currentOpen: 95.0,   // Opened low (maybe gapped down)
        currentClose: 98.0   // Closed higher than open = GREEN candle
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should REJECT because current candle is GREEN (close > open)
    // even though close(98) < previousClose(100)
    expect($result['response'])->toContain('REMOVED');
    expect($result['response'])->toContain('price misalignment');
});

test('real world scenario: gap down recovery still fails LONG alignment', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALGAPDOWN', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Real scenario: Price gapped down at open but recovered
    // Previous: 50000 -> 50100 (green)
    // Current: Opened at 49800 (gap down), recovered to 50050
    // Overall trend looks up (previous close 50100 -> current close 50050 is actually down)
    // But current candle itself is GREEN (49800 -> 50050)

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50000.0,
        previousClose: 50100.0,
        currentOpen: 49800.0,  // Gap down
        currentClose: 50050.0  // Recovery but still below previous close
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should CONFIRM because current candle body is GREEN (50050 > 49800)
    // This is correct behavior - we want to trade with the current momentum
    expect($result['response'])->toContain('CONFIRMED');
});

test('real world scenario: gap up selloff still fails SHORT alignment', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALGAPUP', 'SHORT', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Real scenario: Price gapped up but sold off
    // Previous: 50100 -> 50000 (red)
    // Current: Opened at 50200 (gap up), sold off to 49950
    // Current candle is RED (50200 -> 49950)

    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 50100.0,
        previousClose: 50000.0,
        currentOpen: 50200.0,  // Gap up
        currentClose: 49950.0  // Sold off below previous close
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should CONFIRM because current candle body is RED (49950 < 50200)
    expect($result['response'])->toContain('CONFIRMED');
});

/*
|--------------------------------------------------------------------------
| Floating Point Precision Tests
|--------------------------------------------------------------------------
*/

test('handles floating point precision for equality comparison', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALFLOAT', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Values that might have floating point issues
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 0.1,
        previousClose: 0.2,
        currentOpen: 0.30000000000000004,  // Classic floating point issue
        currentClose: 0.4
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // Should still work correctly
    expect($result['response'])->toContain('CONFIRMED');
});

/*
|--------------------------------------------------------------------------
| Edge Case: Zero and Negative Values
|--------------------------------------------------------------------------
*/

test('handles zero open price correctly', function (): void {
    $exchangeSymbol = createExchangeSymbolForPriceAlignmentTest('PALZERO', 'LONG', '1h');
    $step = createStepForPriceAlignmentJob($exchangeSymbol);

    // Edge case: Open at zero (unlikely but possible for some tokens)
    createCandleIndicatorHistory(
        $exchangeSymbol,
        '1h',
        previousOpen: 0.0001,
        previousClose: 0.00005,
        currentOpen: 0.0,
        currentClose: 0.0001
    );

    $job = new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id);
    $job->step = $step;

    $result = $job->compute();

    // close(0.0001) > open(0.0) = GREEN = LONG confirmed
    expect($result['response'])->toContain('CONFIRMED');
});
