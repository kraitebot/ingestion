<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * Gets or creates the Binance API system for testing.
 */
function getBinanceApiSystemForKlineJob(): ApiSystem
{
    return ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'is_exchange' => true,
            'name' => 'Binance',
            'recvwindow_margin' => 1000,
        ]
    );
}

/**
 * Creates an exchange symbol for kline tests.
 */
function createExchangeSymbolForKlineJob(string $token, string $quote = 'USDT'): ExchangeSymbol
{
    $apiSystem = getBinanceApiSystemForKlineJob();

    return ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => $token,
        'quote' => $quote,
    ]);
}

/**
 * Creates a mock Binance klines response.
 *
 * Binance format: [openTime, open, high, low, close, volume, closeTime, quoteVolume, trades, takerBuyBase, takerBuyQuote, ignore]
 */
function createMockKlinesResponse(int $count = 1, int $baseTimestamp = 1704067200000): array
{
    $klines = [];

    for ($i = 0; $i < $count; $i++) {
        // 5-minute intervals (300000 ms)
        $openTime = $baseTimestamp + ($i * 300000);
        $closeTime = $openTime + 299999;

        $klines[] = [
            $openTime,              // Open time (ms)
            '50000.00',             // Open
            '50200.00',             // High
            '49900.00',             // Low
            '50100.00',             // Close
            '1000.5',               // Volume
            $closeTime,             // Close time
            '50100000.00',          // Quote asset volume
            1000,                   // Number of trades
            '500.25',               // Taker buy base asset volume
            '25050000.00',          // Taker buy quote asset volume
            '0',                    // Ignore
        ];
    }

    return $klines;
}

test('fetches and stores single kline from Binance API', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('BTC_'.$testId);
    $baseTimestamp = 1704067200000; // 2024-01-01 00:00:00 UTC in ms

    // Mock the Binance klines endpoint
    Http::fake([
        '*/fapi/v1/klines*' => Http::response(createMockKlinesResponse(1, $baseTimestamp), 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m',
        limit: 1
    );

    $result = $job->computeApiable();

    expect($result['fetched'])->toBe(1);
    expect($result['stored'])->toBe(1);
    expect($result['exchange_symbol_id'])->toBe($exchangeSymbol->id);
    expect($result['timeframe'])->toBe('5m');

    // Verify candle was stored
    $candle = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '5m')
        ->first();

    expect($candle)->not->toBeNull();
    expect($candle->timestamp)->toBe(1704067200); // Converted from ms to seconds
    // Decimal columns store with higher precision, compare as floats
    expect((float) $candle->open)->toBe(50000.00);
    expect((float) $candle->high)->toBe(50200.00);
    expect((float) $candle->low)->toBe(49900.00);
    expect((float) $candle->close)->toBe(50100.00);
    expect((float) $candle->volume)->toBe(1000.5);
});

test('fetches and stores multiple klines', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('ETH_'.$testId);
    $baseTimestamp = 1704067200000;

    Http::fake([
        '*/fapi/v1/klines*' => Http::response(createMockKlinesResponse(5, $baseTimestamp), 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m',
        limit: 5
    );

    $result = $job->computeApiable();

    expect($result['fetched'])->toBe(5);
    expect($result['stored'])->toBe(5);

    // Verify all candles were stored
    $candles = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '5m')
        ->orderBy('timestamp')
        ->get();

    expect($candles)->toHaveCount(5);

    // Verify timestamps are sequential (5-minute intervals)
    $expectedTimestamp = 1704067200;
    foreach ($candles as $candle) {
        expect($candle->timestamp)->toBe($expectedTimestamp);
        $expectedTimestamp += 300; // 5 minutes
    }
});

test('handles different timeframes', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('SOL_'.$testId);

    Http::fake([
        '*/fapi/v1/klines*' => Http::response(createMockKlinesResponse(1), 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '1h',
        limit: 1
    );

    $result = $job->computeApiable();

    expect($result['timeframe'])->toBe('1h');

    $candle = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '1h')
        ->first();

    expect($candle)->not->toBeNull();
    expect($candle->timeframe)->toBe('1h');
});

test('returns zero when API returns empty response', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('DOGE_'.$testId);

    Http::fake([
        '*/fapi/v1/klines*' => Http::response([], 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m',
        limit: 1
    );

    $result = $job->computeApiable();

    expect($result['stored'])->toBe(0);
    expect($result['message'])->toBe('No klines returned from API');
});

test('upserts existing candles instead of duplicating', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('PEPE_'.$testId);
    $baseTimestamp = 1704067200000;

    // First, insert a candle directly into the database
    Candle::query()->insert([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'timeframe' => '5m',
        'timestamp' => 1704067200,
        'candle_time_utc' => '2024-01-01 00:00:00',
        'candle_time_local' => '2024-01-01 01:00:00',
        'open' => '0.00001000',
        'high' => '0.00001100',
        'low' => '0.00000900',
        'close' => '0.00001050',  // Initial close
        'volume' => '1000000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Verify initial candle exists
    expect(Candle::where('exchange_symbol_id', $exchangeSymbol->id)->count())->toBe(1);

    // Now fetch with updated values (same timestamp, different close)
    $updatedResponse = [
        [
            $baseTimestamp,
            '0.00001000',
            '0.00001100',
            '0.00000900',
            '0.00001200',  // Updated close price
            '1500000000',  // Updated volume
            $baseTimestamp + 299999,
            '15000.00',
            7500,
            '750000000',
            '7500.00',
            '0',
        ],
    ];

    Http::fake([
        '*/fapi/v1/klines*' => Http::response($updatedResponse, 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m',
        limit: 1
    );

    $job->computeApiable();

    // Verify only one candle exists (upserted, not duplicated)
    $candles = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '5m')
        ->get();

    expect($candles)->toHaveCount(1);
    expect((float) $candles->first()->close)->toBe(0.00001200);
    expect((float) $candles->first()->volume)->toBe(1500000000.0);
});

test('normalizes millisecond timestamps to seconds', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('LINK_'.$testId);

    // Binance returns timestamps in milliseconds
    $msTimestamp = 1704067200000; // 2024-01-01 00:00:00 UTC in ms

    Http::fake([
        '*/fapi/v1/klines*' => Http::response(createMockKlinesResponse(1, $msTimestamp), 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m',
        limit: 1
    );

    $job->computeApiable();

    $candle = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '5m')
        ->first();

    // Timestamp should be stored in seconds
    expect($candle->timestamp)->toBe(1704067200);
    // candle_time_utc is cast to Carbon, check the date components
    expect($candle->candle_time_utc->format('Y-m-d H:i:s'))->toBe('2024-01-01 00:00:00');
});

test('stores candle_time_utc and candle_time_local correctly', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('AVAX_'.$testId);

    // 2024-01-15 14:30:00 UTC
    $msTimestamp = 1705328999000;

    Http::fake([
        '*/fapi/v1/klines*' => Http::response([
            [
                $msTimestamp,
                '35.50',
                '36.00',
                '35.00',
                '35.75',
                '50000',
                $msTimestamp + 299999,
                '1787500',
                1500,
                '25000',
                '893750',
                '0',
            ],
        ], 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m',
        limit: 1
    );

    $job->computeApiable();

    $candle = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', '5m')
        ->first();

    expect($candle->candle_time_utc)->not->toBeNull();
    expect($candle->candle_time_local)->not->toBeNull();
});

test('includes symbol info in result', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('UNI_'.$testId);

    Http::fake([
        '*/fapi/v1/klines*' => Http::response(createMockKlinesResponse(1), 200),
    ]);

    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '4h',
        limit: 1
    );

    $result = $job->computeApiable();

    expect($result)->toHaveKey('symbol');
    expect($result['symbol'])->toBe($exchangeSymbol->parsed_trading_pair);
});

test('default limit is 1 when not specified', function () {
    $testId = uniqid();
    $exchangeSymbol = createExchangeSymbolForKlineJob('MATIC_'.$testId);

    Http::fake([
        '*/fapi/v1/klines*' => Http::response(createMockKlinesResponse(1), 200),
    ]);

    // Use default limit
    $job = new FetchKlinesJob(
        exchangeSymbolId: $exchangeSymbol->id,
        timeframe: '5m'
    );

    expect($job->limit)->toBe(1);
});
