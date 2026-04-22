<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcCorrelationJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

beforeEach(function () {
    // Ensure correlation is on for the tests
    config()->set('kraite.correlation.enabled', true);
    config()->set('kraite.correlation.btc_token', 'BTC');
    config()->set('kraite.correlation.window_size', 50);
    config()->set('kraite.correlation.rolling.window_size', 10);
    config()->set('kraite.correlation.rolling.method', 'recent');
    config()->set('kraite.correlation.rolling.step_size', 1);

    Cache::flush();

    $this->apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['is_exchange' => true, 'name' => 'Binance', 'recvwindow_margin' => 1000, 'timeframes' => ['1h']]
    );

    $this->btcMetaSymbol = Symbol::firstOrCreate(['token' => 'BTC'], ['name' => 'Bitcoin']);
    $this->btc = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'symbol_id' => $this->btcMetaSymbol->id,
        'token' => 'BTC',
        'quote' => 'USDT',
    ]);
});

/**
 * Seed matching-timestamp candles for two symbols.
 *
 * Each call advances the UNIX base so subsequent calls inside the same test
 * don't collide on the (symbol, timeframe, timestamp) unique constraint.
 *
 * @param  array<int, float>  $btcCloses   Ordered oldest → newest
 * @param  array<int, float>  $tokenCloses Same length as $btcCloses
 */
function seedPairedCandles(ExchangeSymbol $btc, ExchangeSymbol $token, array $btcCloses, array $tokenCloses, string $timeframe = '1h'): void
{
    expect(count($btcCloses))->toBe(count($tokenCloses), 'seeder expects equal-length series');

    static $invocation = 0;
    $invocation++;
    $base = 1_700_000_000 + ($invocation * 1_000_000); // distinct timestamp window per call
    foreach ($btcCloses as $i => $close) {
        Candle::create([
            'exchange_symbol_id' => $btc->id,
            'timeframe' => $timeframe,
            'timestamp' => $base + $i * 3600,
            'candle_time_utc' => date('Y-m-d H:i:s', $base + $i * 3600),
            'candle_time_local' => date('Y-m-d H:i:s', $base + $i * 3600),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1.0,
        ]);
        Candle::create([
            'exchange_symbol_id' => $token->id,
            'timeframe' => $timeframe,
            'timestamp' => $base + $i * 3600,
            'candle_time_utc' => date('Y-m-d H:i:s', $base + $i * 3600),
            'candle_time_local' => date('Y-m-d H:i:s', $base + $i * 3600),
            'open' => $tokenCloses[$i],
            'high' => $tokenCloses[$i],
            'low' => $tokenCloses[$i],
            'close' => $tokenCloses[$i],
            'volume' => 1.0,
        ]);
    }
}

function runCorrelationJob(int $exchangeSymbolId): array
{
    $step = Step::create([
        'class' => CalculateBtcCorrelationJob::class,
        'queue' => 'indicators',
        'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
    ]);

    $job = new CalculateBtcCorrelationJob($exchangeSymbolId);
    $job->step = $step;

    return $job->compute();
}

test('perfect positive correlation produces pearson near +1', function () {
    $token = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'LINK',
        'quote' => 'USDT',
    ]);

    $series = range(100, 120);
    seedPairedCandles($this->btc, $token, $series, $series);

    $result = runCorrelationJob($token->id);

    expect($result)
        ->toHaveKey('timeframes_calculated', 1)
        ->and($result['timeframes']['1h']['pearson'])->toBeGreaterThan(0.99)
        ->and($result['timeframes']['1h']['pearson'])->toBeLessThanOrEqual(1.0);
});

test('perfect negative correlation produces pearson near -1', function () {
    $token = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'INV',
        'quote' => 'USDT',
    ]);

    $btcSeries = range(100, 120);
    $tokenSeries = range(120, 100); // strictly inverted

    seedPairedCandles($this->btc, $token, $btcSeries, $tokenSeries);

    $result = runCorrelationJob($token->id);

    expect($result['timeframes']['1h']['pearson'])->toBeLessThan(-0.99);
});

test('different kline sets yield different correlation values on the same symbol', function () {
    $token = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'TKA',
        'quote' => 'USDT',
    ]);

    // Scenario A — symbol moves WITH BTC (linear positive)
    seedPairedCandles($this->btc, $token, range(100, 120), range(200, 220));
    $resultA = runCorrelationJob($token->id);
    expect($resultA['timeframes']['1h']['pearson'])->toBeGreaterThan(0.99);

    // Clear the token's candles AND the BTC cache, then reseed with inverted
    // movement. BTC baseline stays untouched so the cache behaviour remains
    // exercised under realistic conditions.
    \Illuminate\Support\Facades\DB::table('candles')
        ->where('exchange_symbol_id', $token->id)->delete();
    \Illuminate\Support\Facades\DB::table('candles')
        ->where('exchange_symbol_id', $this->btc->id)->delete();
    Cache::flush();

    seedPairedCandles($this->btc, $token, range(100, 120), range(220, 200));
    $resultB = runCorrelationJob($token->id);

    expect($resultB['timeframes']['1h']['pearson'])->toBeLessThan(-0.99);

    // Scenario A was positive, scenario B is negative — different kline data
    // produces a distinctly different correlation result on the same symbol.
    expect($resultA['timeframes']['1h']['pearson'])
        ->not->toEqual($resultB['timeframes']['1h']['pearson']);

    // Persisted column on the symbol reflects the latest run (scenario B).
    $token->refresh();
    expect($token->btc_correlation_pearson['1h'])->toBeLessThan(-0.99);
});

test('btc candles are cached so a second symbol does not hit the DB again', function () {
    $tokenA = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'TKA',
        'quote' => 'USDT',
    ]);
    $tokenB = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->apiSystem->id,
        'token' => 'TKB',
        'quote' => 'USDT',
    ]);

    $series = range(100, 120);
    seedPairedCandles($this->btc, $tokenA, $series, $series);
    seedPairedCandles($this->btc, $tokenB, $series, $series);

    // First run populates the BTC cache under a deterministic key
    runCorrelationJob($tokenA->id);

    $cacheKey = "btc_candle_closes:{$this->btc->id}:1h:50";
    expect(Cache::has($cacheKey))->toBeTrue();

    // Mutate the cached BTC series to a distinctive marker. If the job reads
    // the cache (not the DB) on the second run, it sees this marker and
    // produces an error for 'no overlap' (marker timestamps don't match
    // token candles). If it re-reads from DB, it would compute a real
    // correlation. We assert the cache wins.
    Cache::put($cacheKey, [999999999 => 0.0], 30);

    $resultB = runCorrelationJob($tokenB->id);

    expect($resultB['timeframes']['1h'] ?? null)->toHaveKey('error');
});
