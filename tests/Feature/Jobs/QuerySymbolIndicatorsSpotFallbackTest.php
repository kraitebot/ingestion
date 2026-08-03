<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Indicators\RefreshData\CandleComparisonIndicator;
use Kraite\Core\Indicators\RefreshData\ChoppinessIndexIndicator;
use Kraite\Core\Indicators\RefreshData\EMAIndicator;
use Kraite\Core\Indicators\RefreshData\EMAsSameDirection;
use Kraite\Core\Indicators\RefreshData\PivotPointsIndicator;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\ConfirmPriceAlignmentWithDirectionJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\QueryAndStoreSupportAndResistanceJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Support\TaapiMarketDataFreshness;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

final class ThrowingIndicatorForSpotFallbackTest extends BaseIndicator
{
    public string $endpoint = 'ema';

    public function conclusion(): string|bool|array|null
    {
        throw new RuntimeException('simulated local indicator failure');
    }
}

/**
 * @return array<string, Indicator>
 */
function seedIndicatorsForSpotFallbackTest(): array
{
    Indicator::query()->update(['is_active' => false]);

    $definitions = [
        'candle-comparison' => [
            'class' => CandleComparisonIndicator::class,
            'is_computed' => false,
            'parameters' => ['results' => 2],
        ],
        'chop' => [
            'class' => ChoppinessIndexIndicator::class,
            'is_computed' => false,
            'parameters' => ['period' => 14, 'results' => 1, 'backtrack' => 1],
        ],
        'pivotpoints' => [
            'class' => PivotPointsIndicator::class,
            'is_computed' => false,
            'parameters' => ['results' => 1],
        ],
        'ema-40' => [
            'class' => EMAIndicator::class,
            'is_computed' => false,
            'parameters' => ['period' => '40', 'results' => 2, 'backtrack' => 1],
        ],
        'ema-80' => [
            'class' => EMAIndicator::class,
            'is_computed' => false,
            'parameters' => ['period' => '80', 'results' => 2, 'backtrack' => 1],
        ],
        'ema-120' => [
            'class' => EMAIndicator::class,
            'is_computed' => false,
            'parameters' => ['period' => '120', 'results' => 2, 'backtrack' => 1],
        ],
        'emas-same-direction' => [
            'class' => EMAsSameDirection::class,
            'is_computed' => true,
            'parameters' => [],
        ],
    ];

    $indicators = [];

    foreach ($definitions as $canonical => $definition) {
        $indicators[$canonical] = Indicator::query()->create([
            'canonical' => $canonical,
            'type' => 'conclude-indicators',
            'is_active' => true,
            ...$definition,
        ]);
    }

    return $indicators;
}

function createExchangeSymbolForSpotFallbackTest(string $token, ?string $spotToken = null): ExchangeSymbol
{
    ApiSystem::query()->firstOrCreate(
        ['canonical' => 'taapi'],
        ['name' => 'TAAPI', 'is_exchange' => false, 'is_active' => true],
    );

    $binance = ApiSystem::query()->firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true, 'is_active' => true],
    );

    Kraite::findOrFail(1)->forceFill([
        'taapi_secret' => 'spot-fallback-test-secret',
        'timeframes' => ['1h', '4h', '1d'],
    ])->save();

    $symbol = Symbol::factory()->create(['token' => $spotToken ?? $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $binance->id,
        'is_manually_enabled' => true,
        'api_statuses' => [
            'taapi_verified' => true,
            'has_taapi_data' => true,
        ],
    ]);
}

function openCandleTimestampForSpotFallbackTest(string $timeframe = '1h'): int
{
    $seconds = match ($timeframe) {
        '1h' => 3600,
        '4h' => 14_400,
        '1d' => 86_400,
        default => throw new InvalidArgumentException("Unsupported test timeframe {$timeframe}"),
    };

    return intdiv(now()->timestamp, $seconds) * $seconds;
}

/**
 * @return array<string, array<string, mixed>>
 */
function longIndicatorResultsForSpotFallbackTest(int $latestCandleTimestamp): array
{
    return [
        'candle-comparison' => [
            'timestamp' => [$latestCandleTimestamp - 3600, $latestCandleTimestamp],
            'open' => [10.0, 11.0],
            'high' => [11.0, 13.0],
            'low' => [9.0, 10.5],
            'close' => [11.0, 12.5],
            'volume' => [1000.0, 1200.0],
        ],
        'chop' => ['value' => [42.25]],
        'pivotpoints' => [[
            'r3' => 16.0,
            'r2' => 15.0,
            'r1' => 14.0,
            'p' => 12.0,
            's1' => 10.0,
            's2' => 9.0,
            's3' => 8.0,
        ]],
        'ema-40' => ['value' => [10.0, 11.0]],
        'ema-80' => ['value' => [9.0, 10.0]],
        'ema-120' => ['value' => [8.0, 9.0]],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function shortIndicatorResultsForSpotFallbackTest(int $latestCandleTimestamp): array
{
    return [
        'candle-comparison' => [
            'timestamp' => [$latestCandleTimestamp - 3600, $latestCandleTimestamp],
            'open' => [13.0, 12.0],
            'high' => [14.0, 13.0],
            'low' => [11.0, 9.0],
            'close' => [12.0, 10.0],
            'volume' => [1000.0, 1200.0],
        ],
        'chop' => ['value' => [41.0]],
        'pivotpoints' => [[
            'r3' => 16.0,
            'r2' => 15.0,
            'r1' => 14.0,
            'p' => 12.0,
            's1' => 10.0,
            's2' => 9.0,
            's3' => 8.0,
        ]],
        'ema-40' => ['value' => [11.0, 10.0]],
        'ema-80' => ['value' => [10.0, 9.0]],
        'ema-120' => ['value' => [9.0, 8.0]],
    ];
}

/**
 * @param  array<string, array<string, mixed>>  $results
 * @return array{data: array<int, array<string, mixed>>}
 */
function taapiResponseForSpotFallbackTest(
    string $exchange,
    ExchangeSymbol $exchangeSymbol,
    array $results,
    string $timeframe = '1h',
    ?string $responseToken = null,
    bool $customIds = false,
): array {
    $data = [];

    foreach ($results as $canonical => $result) {
        $endpoint = match (true) {
            $canonical === 'candle-comparison' => 'candle',
            str_starts_with($canonical, 'ema-') => 'ema',
            default => $canonical,
        };

        $suffix = match (true) {
            str_starts_with($canonical, 'ema-') => '_'.mb_substr($canonical, 4).'_2_1',
            $canonical === 'chop' => '_14_1_1',
            $canonical === 'candle-comparison' => '_2',
            default => '_1',
        };

        $data[] = [
            'id' => $customIds
                ? "kraite|{$exchange}|{$timeframe}|{$canonical}"
                : "{$exchange}_".($responseToken ?? $exchangeSymbol->token)."/{$exchangeSymbol->quote}_{$timeframe}_{$endpoint}{$suffix}",
            'indicator' => $endpoint,
            'result' => $result,
        ];
    }

    return ['data' => $data];
}

/**
 * @return array<string, IndicatorHistory>
 */
function historiesForSpotFallbackRun(
    ExchangeSymbol $exchangeSymbol,
    string $timeframe,
    int|string $runTimestamp,
): array {
    return IndicatorHistory::query()
        ->with('indicator')
        ->where('exchange_symbol_id', $exchangeSymbol->id)
        ->where('timeframe', $timeframe)
        ->where('timestamp', (string) $runTimestamp)
        ->get()
        ->mapWithKeys(static fn (IndicatorHistory $history): array => [
            $history->indicator->canonical => $history,
        ])
        ->all();
}

function createWorkflowStepForSpotFallbackTest(
    string $class,
    ExchangeSymbol $exchangeSymbol,
    string $blockUuid,
    int $index,
): Step {
    return Step::query()->create([
        'class' => $class,
        'queue' => 'indicators',
        'block_uuid' => $blockUuid,
        'group' => 'alpha',
        'index' => $index,
        'arguments' => [
            'exchangeSymbolId' => $exchangeSymbol->id,
            'timeframe' => '1h',
            'previousConclusions' => [],
        ],
    ]);
}

function ensureTradeConfigurationForSpotFallbackTest(): void
{
    TradeConfiguration::query()->firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'spot-fallback-test',
            'description' => 'Spot fallback test configuration',
            'least_timeframe_index_to_change_indicator' => 1,
            'fast_trade_position_duration_seconds' => 3600,
            'fast_trade_position_closed_age_seconds' => 1800,
            'disable_exchange_symbol_from_negative_pnl_position' => false,
        ],
    );
}

test('keeps a complete fresh futures run authoritative and never calls spot', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('FRESHFUT'.Str::upper(Str::random(6)));
    $wrongSymbol = createExchangeSymbolForSpotFallbackTest('WRONGFUT'.Str::upper(Str::random(6)));
    $freshResults = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    IndicatorHistory::query()->create([
        'exchange_symbol_id' => $wrongSymbol->id,
        'indicator_id' => Indicator::query()->where('canonical', 'ema-40')->value('id'),
        'timeframe' => '4h',
        'timestamp' => (string) now()->subMinute()->timestamp,
        'data' => ['value' => [777.0, 888.0]],
        'conclusion' => 'LONG',
    ]);
    $wrongHistoryId = IndicatorHistory::query()
        ->where('exchange_symbol_id', $wrongSymbol->id)
        ->value('id');

    Http::fake([
        'https://api.taapi.io/*' => Http::response(
            taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshResults),
        ),
    ]);

    expect(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse();

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'errors' => [],
        'fallback_used' => false,
        'unavailable_indicators' => [],
    ]);

    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect(array_keys($histories))->toHaveCount(7)
        ->and($histories['candle-comparison']->conclusion)->toBe('LONG')
        ->and($histories['chop']->conclusion)->toBe('1')
        ->and($histories['ema-40']->conclusion)->toBe('LONG')
        ->and($histories['ema-80']->conclusion)->toBe('LONG')
        ->and($histories['ema-120']->conclusion)->toBe('LONG')
        ->and($histories['emas-same-direction']->conclusion)->toBe('LONG')
        ->and($histories['candle-comparison']->taapi_construct_id)->toStartWith('binancefutures_')
        ->and($histories['ema-80']->taapi_construct_id)->toStartWith('binancefutures_')
        ->and(IndicatorHistory::query()->whereKey($wrongHistoryId)->value('data'))->toBe(['value' => [777, 888]])
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(static function (Request $request): bool {
        return data_get($request->data(), 'construct.0.exchange') === 'binancefutures';
    });
});

test('does not call spot when fresh futures indicators are genuinely inconclusive', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('FUTINCON'.Str::upper(Str::random(6)));
    $freshResults = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $freshResults['chop'] = ['value' => [65.0]];

    Http::fake([
        'https://api.taapi.io/*' => Http::response(
            taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshResults),
        ),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result['status'])->toBe('fresh')
        ->and($result['fallback_used'])->toBeFalse()
        ->and($histories['chop']->conclusion)->toBe('0');

    Http::assertSentCount(1);
});

test('replaces a fully stale futures run with fresh spot data', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('STALEFUT'.Str::upper(Str::random(6)));
    $staleFutures = shortIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest() - 72 * 3600);
    $freshSpot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $staleFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'fallback_used' => true,
        'unavailable_indicators' => [],
    ])
        ->and($result['freshness']['binancefutures']['is_fresh'])->toBeFalse()
        ->and($result['freshness']['binance']['is_fresh'])->toBeTrue()
        ->and($histories['candle-comparison']->conclusion)->toBe('LONG')
        ->and($histories['emas-same-direction']->conclusion)->toBe('LONG');

    foreach (['candle-comparison', 'chop', 'pivotpoints', 'ema-40', 'ema-80', 'ema-120'] as $canonical) {
        expect($histories[$canonical]->taapi_construct_id)->toStartWith('binance_');
    }

    Http::assertSentCount(2);
    $exchanges = collect(Http::recorded())
        ->map(static fn (array $pair): mixed => data_get($pair[0]->data(), 'construct.0.exchange'))
        ->all();
    expect($exchanges)->toBe(['binancefutures', 'binance'])
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();
});

test('queries the canonical spot pair and normalizes every price-bearing result to futures contract units', function (): void {
    seedIndicatorsForSpotFallbackTest();
    ensureTradeConfigurationForSpotFallbackTest();
    $spotToken = 'FLOKI'.Str::upper(Str::random(6));
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('1000'.$spotToken, $spotToken);
    $currentTimestamp = openCandleTimestampForSpotFallbackTest();
    $staleFutures = shortIndicatorResultsForSpotFallbackTest($currentTimestamp - 72 * 3600);
    $freshSpot = longIndicatorResultsForSpotFallbackTest($currentTimestamp);
    $blockUuid = Str::uuid()->toString();
    $queryStep = createWorkflowStepForSpotFallbackTest(
        QuerySymbolIndicatorsJob::class,
        $exchangeSymbol,
        $blockUuid,
        1,
    );
    $concludeStep = createWorkflowStepForSpotFallbackTest(
        ConcludeSymbolDirectionAtTimeframeJob::class,
        $exchangeSymbol,
        $blockUuid,
        2,
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $staleFutures))
            ->push(taapiResponseForSpotFallbackTest(
                'binance',
                $exchangeSymbol,
                $freshSpot,
                responseToken: $spotToken,
            )),
    ]);

    $queryResult = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $queryStep->update(['response' => $queryResult]);
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $queryResult['run_timestamp']);

    expect($queryResult['status'])->toBe('fresh')
        ->and($queryResult['sources']['binance'])->toHaveCount(6)
        ->and($histories['candle-comparison']->data['open'])->toBe([
            '10000.0000000000000000',
            '11000.0000000000000000',
        ])
        ->and($histories['candle-comparison']->data['close'])->toBe([
            '11000.0000000000000000',
            '12500.0000000000000000',
        ])
        ->and($histories['candle-comparison']->data['volume'])->toBe([1000, 1200])
        ->and($histories['ema-80']->data['value'])->toBe([
            '9000.0000000000000000',
            '10000.0000000000000000',
        ])
        ->and($histories['chop']->data['value'])->toBe([42.25])
        ->and($histories['pivotpoints']->data[0]['r1'])->toBe('14000.0000000000000000');

    $spotRequest = Http::recorded()[1][0];

    expect(data_get($spotRequest->data(), 'construct.0.symbol'))->toBe("{$spotToken}/USDT");

    $concludeJob = new ConcludeSymbolDirectionAtTimeframeJob($exchangeSymbol->id, '1h');
    $concludeJob->step = $concludeStep;
    $concludeResult = $concludeJob->compute();
    $pivotResult = (new QueryAndStoreSupportAndResistanceJob($exchangeSymbol->id))->compute();
    $alignmentResult = (new ConfirmPriceAlignmentWithDirectionJob($exchangeSymbol->id))->compute();

    expect($concludeResult)->toMatchArray([
        'result' => 'concluded',
        'direction' => 'LONG',
    ])
        ->and($pivotResult['status'])->toBe('stored')
        ->and($alignmentResult['response'])->toContain('CONFIRMED')
        ->and($exchangeSymbol->fresh()->pivot_r1)->toBe('14000.00000000')
        ->and($exchangeSymbol->fresh()->direction)->toBe('LONG')
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();
});

test('normalizes supported multiplier contract prefixes during full spot fallback', function (
    string $futuresPrefix,
    string $expectedEmaValue,
): void {
    seedIndicatorsForSpotFallbackTest();
    $spotToken = 'UNIT'.Str::upper(Str::random(6));
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest($futuresPrefix.$spotToken, $spotToken);
    $currentTimestamp = openCandleTimestampForSpotFallbackTest();
    $staleFutures = shortIndicatorResultsForSpotFallbackTest($currentTimestamp - 72 * 3600);
    $freshSpot = longIndicatorResultsForSpotFallbackTest($currentTimestamp);

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $staleFutures))
            ->push(taapiResponseForSpotFallbackTest(
                'binance',
                $exchangeSymbol,
                $freshSpot,
                responseToken: $spotToken,
            )),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result['status'])->toBe('fresh')
        ->and($histories['ema-80']->data['value'][1])->toBe($expectedEmaValue)
        ->and(data_get(Http::recorded()[1][0]->data(), 'construct.0.symbol'))->toBe("{$spotToken}/USDT");
})->with([
    '1000 contract' => ['1000', '10000.0000000000000000'],
    '1000000 contract' => ['1000000', '10000000.0000000000000000'],
    '1M contract' => ['1M', '10000000.0000000000000000'],
]);

test('uses a linked spot alias without inventing a price multiplier', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $spotToken = 'BTC'.Str::upper(Str::random(6));
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('XBT'.Str::upper(Str::random(6)), $spotToken);
    $currentTimestamp = openCandleTimestampForSpotFallbackTest();
    $staleFutures = shortIndicatorResultsForSpotFallbackTest($currentTimestamp - 72 * 3600);
    $freshSpot = longIndicatorResultsForSpotFallbackTest($currentTimestamp);

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $staleFutures))
            ->push(taapiResponseForSpotFallbackTest(
                'binance',
                $exchangeSymbol,
                $freshSpot,
                responseToken: $spotToken,
            )),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result['status'])->toBe('fresh')
        ->and($histories['ema-80']->data['value'])->toBe([9, 10])
        ->and($histories['pivotpoints']->data[0]['r1'])->toBe(14)
        ->and(data_get(Http::recorded()[1][0]->data(), 'construct.0.symbol'))->toBe("{$spotToken}/USDT");
});

test('uses spot only for a missing futures indicator and computes EMAs from the mixed fresh set', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('MIXEDSRC'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    unset($freshFutures['ema-80']);
    $freshSpot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $freshSpot = array_intersect_key($freshSpot, array_flip(['candle-comparison', 'ema-80']));

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'fallback_used' => true,
        'unavailable_indicators' => [],
    ])
        ->and($histories['candle-comparison']->taapi_construct_id)->toStartWith('binancefutures_')
        ->and($histories['ema-40']->taapi_construct_id)->toStartWith('binancefutures_')
        ->and($histories['ema-80']->taapi_construct_id)->toStartWith('binance_')
        ->and($histories['ema-120']->taapi_construct_id)->toStartWith('binancefutures_')
        ->and($histories['emas-same-direction']->conclusion)->toBe('LONG')
        ->and($histories['emas-same-direction']->data['ema-80']['result']['value'])->toBe([9, 10]);

    $secondRequest = Http::recorded()[1][0];
    $spotIndicators = collect(data_get($secondRequest->data(), 'construct.0.indicators'));

    expect(data_get($secondRequest->data(), 'construct.0.exchange'))->toBe('binance')
        ->and($spotIndicators->pluck('indicator')->all())->toBe(['candle', 'ema'])
        ->and($spotIndicators->last()['period'])->toBe('80');
});

test('preserves fresh futures values while normalizing only the missing spot indicator', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $spotToken = 'BONK'.Str::upper(Str::random(6));
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('1000'.$spotToken, $spotToken);
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $freshFutures['candle-comparison']['open'] = [10_000.0, 11_000.0];
    $freshFutures['candle-comparison']['high'] = [11_000.0, 13_000.0];
    $freshFutures['candle-comparison']['low'] = [9_000.0, 10_500.0];
    $freshFutures['candle-comparison']['close'] = [11_000.0, 12_500.0];
    $freshFutures['ema-40'] = ['value' => [10_000.0, 11_000.0]];
    $freshFutures['ema-120'] = ['value' => [8_000.0, 9_000.0]];
    unset($freshFutures['ema-80']);
    $freshSpot = array_intersect_key(
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true, 'ema-80' => true],
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures))
            ->push(taapiResponseForSpotFallbackTest(
                'binance',
                $exchangeSymbol,
                $freshSpot,
                responseToken: $spotToken,
            )),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result['status'])->toBe('fresh')
        ->and($histories['candle-comparison']->data['close'])->toBe([11_000, 12_500])
        ->and($histories['candle-comparison']->taapi_construct_id)->toStartWith('binancefutures_')
        ->and($histories['ema-40']->data['value'])->toBe([10_000, 11_000])
        ->and($histories['ema-80']->data['value'])->toBe([
            '9000.0000000000000000',
            '10000.0000000000000000',
        ])
        ->and($histories['ema-80']->taapi_construct_id)->toStartWith('binance_')
        ->and($histories['ema-120']->data['value'])->toBe([8_000, 9_000])
        ->and($histories['emas-same-direction']->conclusion)->toBe('LONG');
});

test('treats an empty futures indicator result as unavailable and replaces only that result from spot', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('EMPTYFUT'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $freshFutures['ema-80'] = [];
    $freshSpot = array_intersect_key(
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true, 'ema-80' => true],
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'fallback_used' => true,
        'unavailable_indicators' => [],
    ])
        ->and($histories['ema-80']->taapi_construct_id)->toStartWith('binance_')
        ->and($histories['ema-80']->data['value'])->toBe([9, 10])
        ->and($histories['emas-same-direction']->conclusion)->toBe('LONG');

    Http::assertSentCount(2);
});

test('falls back from a failed futures request and stores a complete fresh spot run', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('FUTFAIL'.Str::upper(Str::random(6)));
    $freshSpot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['errors' => ['futures unavailable']], 503)
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result['status'])->toBe('fresh')
        ->and($result['fallback_used'])->toBeTrue()
        ->and($result['sources']['binance'])->toBe([
            'candle-comparison',
            'chop',
            'pivotpoints',
            'ema-40',
            'ema-80',
            'ema-120',
        ])
        ->and($histories['candle-comparison']->conclusion)->toBe('LONG')
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(2);
});

test('falls back safely when futures returns a malformed bulk data shape', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BADFUTSHAPE'.Str::upper(Str::random(6)));
    $freshSpot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['data' => 'not-an-indicator-list'])
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'fallback_used' => true,
        'total_responses' => 6,
        'unavailable_indicators' => [],
    ])
        ->and($result['errors'])->toContain('binancefutures request returned invalid data list')
        ->and($result['sources']['binancefutures'])->toBe([])
        ->and($result['sources']['binance'])->toHaveCount(6)
        ->and($histories['candle-comparison']->taapi_construct_id)->toStartWith('binance_');

    Http::assertSentCount(2);
});

test('keeps fresh futures results unavailable when the required spot response has a malformed bulk data shape', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BADSPOTSHAPE'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    unset($freshFutures['ema-80']);

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures))
            ->push(['data' => 'not-an-indicator-list']),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'unavailable',
        'stored' => 5,
        'fallback_used' => true,
        'total_responses' => 5,
        'unavailable_indicators' => ['ema-80', 'emas-same-direction'],
    ])
        ->and($result['errors'])->toContain('binance request returned invalid data list')
        ->and($result['sources']['binancefutures'])->toHaveCount(5)
        ->and($result['sources']['binance'])->toBe([])
        ->and(array_key_exists('ema-80', $histories))->toBeFalse()
        ->and(array_key_exists('emas-same-direction', $histories))->toBeFalse();

    Http::assertSentCount(2);
});

test('replaces an ambiguous duplicated futures indicator instead of trusting response order', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('DUPFUT'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $futuresResponse = taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures);
    $duplicatedEma = collect($futuresResponse['data'])
        ->first(static fn (array $entry): bool => str_contains($entry['id'], '_ema_80_'));
    $duplicatedEma['result'] = ['value' => [999.0, 1.0]];
    $futuresResponse['data'][] = $duplicatedEma;
    $freshSpot = array_intersect_key(
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true, 'ema-80' => true],
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push($futuresResponse)
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'fallback_used' => true,
        'unavailable_indicators' => [],
    ])
        ->and($result['errors'])->toContain('binancefutures returned duplicate ema-80 results')
        ->and($histories['ema-80']->taapi_construct_id)->toStartWith('binance_')
        ->and($histories['ema-80']->data['value'])->toBe([9, 10])
        ->and($histories['emas-same-direction']->conclusion)->toBe('LONG');

    Http::assertSentCount(2);
});

test('marks the run unavailable when both futures and spot are stale without storing stale values', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BOTHSTALE'.Str::upper(Str::random(6)));
    $staleFutures = shortIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest() - 72 * 3600);
    $staleSpot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest() - 3600);

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $staleFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $staleSpot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();

    expect($result)->toMatchArray([
        'status' => 'unavailable',
        'stored' => 0,
        'fallback_used' => true,
        'unavailable_indicators' => [
            'candle-comparison',
            'chop',
            'pivotpoints',
            'ema-40',
            'ema-80',
            'ema-120',
            'emas-same-direction',
        ],
    ])
        ->and($result['freshness']['binancefutures']['is_fresh'])->toBeFalse()
        ->and($result['freshness']['binance']['is_fresh'])->toBeFalse()
        ->and(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse()
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(2);
});

test('rethrows a real provider outage when futures and spot both fail', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BOTHFAIL'.Str::upper(Str::random(6)));

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['errors' => ['futures unavailable']], 503)
            ->push(['errors' => ['spot unavailable']], 503),
    ]);

    expect(fn (): array => (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable())
        ->toThrow(GuzzleRequestException::class);

    $logs = ApiRequestLog::query()
        ->where('relatable_id', $exchangeSymbol->id)
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('http_response_code')->all())->toBe([503, 503])
        ->and(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse()
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(2);
});

test('treats proven no-candle responses from both sources as silent unavailability', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('NOCANDLES'.Str::upper(Str::random(6)));

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['errors' => ['No candles were found!']], 400)
            ->push(['errors' => ['No candle data for this symbol']], 404),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();

    expect($result)->toMatchArray([
        'status' => 'unavailable',
        'stored' => 0,
        'fallback_used' => true,
    ])
        ->and($result['unavailable_indicators'])->toHaveCount(7)
        ->and(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse()
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(2);
});

test('does not silence a TAAPI plan error when spot also has no usable data', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('PLANERROR'.Str::upper(Str::random(6)));

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['errors' => ['You requested more constructs than your plan allows']], 400)
            ->push(['errors' => ['No candles were found!']], 400),
    ]);

    expect(fn (): array => (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable())
        ->toThrow(GuzzleRequestException::class);

    expect(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(2);
});

test('retries the step lifecycle when both sources return transient server errors', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('RETRY503'.Str::upper(Str::random(6)));
    $step = createWorkflowStepForSpotFallbackTest(
        QuerySymbolIndicatorsJob::class,
        $exchangeSymbol,
        Str::uuid()->toString(),
        1,
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['errors' => ['futures unavailable']], 503)
            ->push(['errors' => ['spot unavailable']], 503),
    ]);

    expect((int) $step->retries)->toBe(0)
        ->and($step->response)->toBeNull();

    $job = new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h');
    $job->step = $step;
    $job->handle();

    $step->refresh();

    expect($step->state)->toBeInstanceOf(Pending::class)
        ->and($step->retries)->toBe(1)
        ->and($step->response)->toBeNull()
        ->and($step->dispatch_after)->not->toBeNull()
        ->and(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(2);
});

test('accepts only the exact current candle boundary and falls back for previous or future candles', function (
    int $offsetSeconds,
    bool $expectsFallback,
): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BOUNDARY'.Str::upper(Str::random(6)));
    $futures = longIndicatorResultsForSpotFallbackTest(
        openCandleTimestampForSpotFallbackTest() + $offsetSeconds,
    );
    $spot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    $sequence = Http::sequence()->push(
        taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $futures),
    );

    if ($expectsFallback) {
        $sequence->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $spot));
    }

    Http::fake(['https://api.taapi.io/*' => $sequence]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();

    expect($result['status'])->toBe('fresh')
        ->and($result['fallback_used'])->toBe($expectsFallback);

    Http::assertSentCount($expectsFallback ? 2 : 1);
})->with([
    'current candle' => [0, false],
    'previous candle' => [-3600, true],
    'future candle' => [3600, true],
]);

test('accepts fresh futures candle boundaries on every configured timeframe', function (string $timeframe): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('LADDER'.Str::upper(Str::random(6)));
    $futures = longIndicatorResultsForSpotFallbackTest(
        openCandleTimestampForSpotFallbackTest($timeframe),
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::response(
            taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $futures, $timeframe),
        ),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, $timeframe))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, $timeframe, $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'fresh',
        'stored' => 7,
        'fallback_used' => false,
        'unavailable_indicators' => [],
    ])
        ->and($result['freshness']['binancefutures']['latest_timestamp'])
        ->toBe(openCandleTimestampForSpotFallbackTest($timeframe))
        ->and(array_keys($histories))->toHaveCount(7)
        ->and($histories['candle-comparison']->timeframe)->toBe($timeframe);

    Http::assertSentCount(1);
})->with(['1h', '4h', '1d']);

test('uses explicit stable request ids and maps responses without provider metadata', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('CUSTOMID'.Str::upper(Str::random(6)));
    $response = taapiResponseForSpotFallbackTest(
        'binancefutures',
        $exchangeSymbol,
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        customIds: true,
    );
    $response['data'] = array_map(static function (array $entry): array {
        unset($entry['indicator']);

        return $entry;
    }, $response['data']);

    Http::fake(['https://api.taapi.io/*' => Http::response($response)]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $requestIds = collect(data_get(Http::recorded()[0][0]->data(), 'construct.0.indicators'))
        ->pluck('id')
        ->all();

    expect($result['status'])->toBe('fresh')
        ->and($result['errors'])->toBe([])
        ->and($requestIds)->toBe([
            'kraite|binancefutures|1h|candle-comparison',
            'kraite|binancefutures|1h|chop',
            'kraite|binancefutures|1h|pivotpoints',
            'kraite|binancefutures|1h|ema-40',
            'kraite|binancefutures|1h|ema-80',
            'kraite|binancefutures|1h|ema-120',
        ]);

    Http::assertSentCount(1);
});

test('rejects unconfigured and unparsable timeframes without calling TAAPI', function (string $timeframe): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BADTIME'.Str::upper(Str::random(6)));

    Http::fake();

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, $timeframe))->computeApiable();
    $freshness = TaapiMarketDataFreshness::fromIndicatorData([
        'candle-comparison' => [
            'result' => ['timestamp' => [now()->timestamp]],
        ],
    ], $timeframe);

    expect($result)->toMatchArray([
        'status' => 'unavailable',
        'stored' => 0,
    ])
        ->and($result['errors'])->toContain("Unsupported indicator timeframe: {$timeframe}")
        ->and($freshness['is_fresh'])->toBeFalse()
        ->and(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertNothingSent();
})->with(['12h', 'invalid', '0h']);

test('normalizes spot candle timestamps and rejects missing or non-current anchors', function (
    string $timestampShape,
    bool $expectedFresh,
): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('SPOTSTAMP'.Str::upper(Str::random(6)));
    $current = openCandleTimestampForSpotFallbackTest();
    $staleFutures = shortIndicatorResultsForSpotFallbackTest($current - 72 * 3600);
    $spot = longIndicatorResultsForSpotFallbackTest($current);

    $spot['candle-comparison']['timestamp'] = match ($timestampShape) {
        'seconds' => [$current - 3600, $current],
        'milliseconds' => [($current - 3600) * 1000, $current * 1000],
        'microseconds' => [($current - 3600) * 1_000_000, $current * 1_000_000],
        'previous' => [$current - 7200, $current - 3600],
        'future' => [$current, $current + 3600],
        'missing' => null,
        'nonnumeric' => ['old', 'new'],
        default => throw new InvalidArgumentException("Unsupported timestamp shape {$timestampShape}"),
    };

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $staleFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $spot)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();

    expect($result['status'])->toBe($expectedFresh ? 'fresh' : 'unavailable')
        ->and($result['stored'])->toBe($expectedFresh ? 7 : 0)
        ->and($result['fallback_used'])->toBeTrue()
        ->and($result['freshness']['binance']['is_fresh'])->toBe($expectedFresh)
        ->and(
            IndicatorHistory::query()
                ->where('exchange_symbol_id', $exchangeSymbol->id)
                ->where('timestamp', (string) $result['run_timestamp'])
                ->exists(),
        )->toBe($expectedFresh);

    Http::assertSentCount(2);
})->with([
    'seconds' => ['seconds', true],
    'milliseconds' => ['milliseconds', true],
    'microseconds' => ['microseconds', true],
    'previous candle' => ['previous', false],
    'future candle' => ['future', false],
    'missing timestamp' => ['missing', false],
    'nonnumeric timestamp' => ['nonnumeric', false],
]);

test('does not deactivate or notify for repeated futures no-data errors when spot recovers the run', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('SILENT400'.Str::upper(Str::random(6)));
    $freshSpot = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $sequence = Http::sequence();

    foreach (range(1, 3) as $attempt) {
        $sequence
            ->push(['errors' => ['No candles were found!']], 400)
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot));
    }

    Http::fake(['https://api.taapi.io/*' => $sequence]);

    expect($exchangeSymbol->fresh()->api_statuses)->toMatchArray([
        'taapi_verified' => true,
        'has_taapi_data' => true,
    ]);

    foreach (range(1, 3) as $attempt) {
        $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();

        expect($result['status'])->toBe('fresh')
            ->and($result['fallback_used'])->toBeTrue()
            ->and($result['stored'])->toBe(7);
    }

    expect($exchangeSymbol->fresh()->api_statuses)->toMatchArray([
        'taapi_verified' => true,
        'has_taapi_data' => true,
    ])
        ->and($exchangeSymbol->fresh()->has_no_indicator_data)->toBeFalse()
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertSentCount(6);
});

test('does not compute or conclude from an incomplete mixed run', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('PARTIAL'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    unset($freshFutures['ema-80']);
    $spotAnchorOnly = array_intersect_key(
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true],
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $spotAnchorOnly)),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'unavailable',
        'stored' => 5,
        'fallback_used' => true,
        'unavailable_indicators' => ['ema-80', 'emas-same-direction'],
    ])
        ->and(array_keys($histories))->toHaveCount(5)
        ->and(array_key_exists('ema-80', $histories))->toBeFalse()
        ->and(array_key_exists('emas-same-direction', $histories))->toBeFalse();
});

test('marks a run unavailable and skips computed indicators when local indicator evaluation fails', function (): void {
    seedIndicatorsForSpotFallbackTest();
    Indicator::query()
        ->where('canonical', 'ema-80')
        ->update(['class' => ThrowingIndicatorForSpotFallbackTest::class]);
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('LOCALFAIL'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    Http::fake([
        'https://api.taapi.io/*' => Http::response(
            taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures),
        ),
    ]);

    $result = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $histories = historiesForSpotFallbackRun($exchangeSymbol, '1h', $result['run_timestamp']);

    expect($result)->toMatchArray([
        'status' => 'unavailable',
        'stored' => 5,
        'fallback_used' => false,
        'unavailable_indicators' => ['ema-80', 'emas-same-direction'],
    ])
        ->and($result['errors'])->toContain('Indicator ema-80 failed: simulated local indicator failure')
        ->and(array_key_exists('ema-80', $histories))->toBeFalse()
        ->and(array_key_exists('emas-same-direction', $histories))->toBeFalse();

    Http::assertSentCount(1);
});

test('does not disguise a local construction defect as unavailable provider data', function (): void {
    seedIndicatorsForSpotFallbackTest();
    Indicator::query()
        ->where('canonical', 'ema-80')
        ->update(['class' => 'Kraite\\Core\\Indicators\\MissingIndicatorClass']);
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('BROKENCLASS'.Str::upper(Str::random(6)));

    Http::fake();

    expect(fn (): array => (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable())
        ->toThrow(Error::class);

    expect(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->exists())->toBeFalse();

    Http::assertNothingSent();
});

test('isolates two complete runs created in the same second', function (): void {
    seedIndicatorsForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('RETRYONCE'.Str::upper(Str::random(6)));
    $longFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    unset($longFutures['ema-80']);
    $longSpot = array_intersect_key(
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true, 'ema-80' => true],
    );
    $shortFutures = shortIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    unset($shortFutures['ema-80']);
    $shortSpot = array_intersect_key(
        shortIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true, 'ema-80' => true],
    );

    Carbon::setTestNow(now()->startOfSecond());

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $longFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $longSpot))
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $shortFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $shortSpot)),
    ]);

    $first = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $firstIds = collect(historiesForSpotFallbackRun($exchangeSymbol, '1h', $first['run_timestamp']))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $second = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $secondIds = collect(historiesForSpotFallbackRun($exchangeSymbol, '1h', $second['run_timestamp']))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($second['run_timestamp'])->not->toBe($first['run_timestamp'])
        ->and($firstIds)->toHaveCount(7)
        ->and($secondIds)->toHaveCount(7)
        ->and(array_intersect($firstIds, $secondIds))->toBe([])
        ->and(historiesForSpotFallbackRun($exchangeSymbol, '1h', $first['run_timestamp'])['emas-same-direction']->conclusion)->toBe('LONG')
        ->and(historiesForSpotFallbackRun($exchangeSymbol, '1h', $second['run_timestamp'])['emas-same-direction']->conclusion)->toBe('SHORT')
        ->and(IndicatorHistory::query()->where('exchange_symbol_id', $exchangeSymbol->id)->count())->toBe(14);

    Http::assertSentCount(4);

    Carbon::setTestNow();
});

test('concludes from a fresh mixed futures and spot run', function (): void {
    seedIndicatorsForSpotFallbackTest();
    ensureTradeConfigurationForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('MIXCONCLUDE'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    unset($freshFutures['ema-80']);
    $freshSpot = array_intersect_key(
        longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest()),
        ['candle-comparison' => true, 'ema-80' => true],
    );
    $blockUuid = Str::uuid()->toString();
    $queryStep = createWorkflowStepForSpotFallbackTest(
        QuerySymbolIndicatorsJob::class,
        $exchangeSymbol,
        $blockUuid,
        1,
    );
    $concludeStep = createWorkflowStepForSpotFallbackTest(
        ConcludeSymbolDirectionAtTimeframeJob::class,
        $exchangeSymbol,
        $blockUuid,
        2,
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures))
            ->push(taapiResponseForSpotFallbackTest('binance', $exchangeSymbol, $freshSpot)),
    ]);

    expect($exchangeSymbol->fresh()->direction)->toBeNull();

    $queryResult = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $queryStep->update(['response' => $queryResult]);

    $concludeJob = new ConcludeSymbolDirectionAtTimeframeJob($exchangeSymbol->id, '1h');
    $concludeJob->step = $concludeStep;
    $concludeResult = $concludeJob->compute();

    expect($concludeResult)->toMatchArray([
        'result' => 'concluded',
        'direction' => 'LONG',
        'timeframe' => '1h',
    ])
        ->and($exchangeSymbol->fresh()->direction)->toBe('LONG')
        ->and($exchangeSymbol->fresh()->indicators_timeframe)->toBe('1h')
        ->and($exchangeSymbol->fresh()->indicators_values['ema-80']['result']['value'])->toBe([9, 10])
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();
});

test('rejects an unavailable query run even when older histories still look market-fresh', function (): void {
    $indicators = seedIndicatorsForSpotFallbackTest();
    ensureTradeConfigurationForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('OLDDANGER'.Str::upper(Str::random(6)));
    $exchangeSymbol->updateSaving([
        'direction' => 'SHORT',
        'indicators_timeframe' => '1h',
        'indicators_values' => ['existing' => ['result' => true]],
        'has_invalid_indicator_direction' => false,
    ]);
    $oldRunTimestamp = now()->subMinute()->timestamp;
    $oldResults = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());

    foreach ($oldResults as $canonical => $data) {
        $indicator = $indicators[$canonical];
        $indicatorInstance = new $indicator->class($exchangeSymbol, ['interval' => '1h']);
        $indicatorInstance->load($data);
        IndicatorHistory::query()->create([
            'exchange_symbol_id' => $exchangeSymbol->id,
            'indicator_id' => $indicator->id,
            'timeframe' => '1h',
            'timestamp' => (string) $oldRunTimestamp,
            'data' => $data,
            'conclusion' => $indicatorInstance->conclusion(),
        ]);
    }

    IndicatorHistory::query()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicators['emas-same-direction']->id,
        'timeframe' => '1h',
        'timestamp' => (string) $oldRunTimestamp,
        'data' => collect($oldResults)
            ->map(static fn (array $result): array => ['result' => $result])
            ->all(),
        'conclusion' => 'LONG',
    ]);

    $blockUuid = Str::uuid()->toString();
    $queryStep = createWorkflowStepForSpotFallbackTest(
        QuerySymbolIndicatorsJob::class,
        $exchangeSymbol,
        $blockUuid,
        1,
    );
    $concludeStep = createWorkflowStepForSpotFallbackTest(
        ConcludeSymbolDirectionAtTimeframeJob::class,
        $exchangeSymbol,
        $blockUuid,
        2,
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::sequence()
            ->push(['errors' => ['No candles were found for this symbol.']], 400)
            ->push(['errors' => ['No candle data was found for this symbol.']], 404),
    ]);

    $queryResult = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $queryStep->update(['response' => $queryResult]);

    $concludeJob = new ConcludeSymbolDirectionAtTimeframeJob($exchangeSymbol->id, '1h');
    $concludeJob->step = $concludeStep;
    $concludeResult = $concludeJob->compute();

    expect($queryResult['status'])->toBe('unavailable')
        ->and($concludeResult)->toMatchArray([
            'result' => 'inconclusive',
            'reason' => 'stale_indicator_data',
            'retry' => 'next_refresh_cycle',
        ])
        ->and($exchangeSymbol->fresh()->direction)->toBeNull()
        ->and($exchangeSymbol->fresh()->indicators_values)->toBeNull()
        ->and($exchangeSymbol->fresh()->has_invalid_indicator_direction)->toBeTrue()
        ->and(Step::query()->where('block_uuid', $blockUuid)->where('index', '>', 2)->exists())->toBeFalse()
        ->and(NotificationLog::query()->where('relatable_id', $exchangeSymbol->id)->exists())->toBeFalse();
});

test('conclusion pins to the query run and ignores a later spot history for a fresh futures indicator', function (): void {
    $indicators = seedIndicatorsForSpotFallbackTest();
    ensureTradeConfigurationForSpotFallbackTest();
    $exchangeSymbol = createExchangeSymbolForSpotFallbackTest('PINRUN'.Str::upper(Str::random(6)));
    $freshFutures = longIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest());
    $blockUuid = Str::uuid()->toString();
    $queryStep = createWorkflowStepForSpotFallbackTest(
        QuerySymbolIndicatorsJob::class,
        $exchangeSymbol,
        $blockUuid,
        1,
    );
    $concludeStep = createWorkflowStepForSpotFallbackTest(
        ConcludeSymbolDirectionAtTimeframeJob::class,
        $exchangeSymbol,
        $blockUuid,
        2,
    );

    Http::fake([
        'https://api.taapi.io/*' => Http::response(
            taapiResponseForSpotFallbackTest('binancefutures', $exchangeSymbol, $freshFutures),
        ),
    ]);

    $queryResult = (new QuerySymbolIndicatorsJob($exchangeSymbol->id, '1h'))->computeApiable();
    $queryStep->update(['response' => $queryResult]);

    IndicatorHistory::query()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicators['candle-comparison']->id,
        'taapi_construct_id' => 'binance_later_spot_history',
        'timeframe' => '1h',
        'timestamp' => now()->addSecond()->format('Uu').'ffffffffffffffff',
        'data' => shortIndicatorResultsForSpotFallbackTest(openCandleTimestampForSpotFallbackTest())['candle-comparison'],
        'conclusion' => 'SHORT',
    ]);

    $concludeJob = new ConcludeSymbolDirectionAtTimeframeJob($exchangeSymbol->id, '1h');
    $concludeJob->step = $concludeStep;
    $result = $concludeJob->compute();

    expect($result)->toMatchArray([
        'result' => 'concluded',
        'direction' => 'LONG',
    ])
        ->and($exchangeSymbol->fresh()->direction)->toBe('LONG')
        ->and($exchangeSymbol->fresh()->indicators_values['candle-comparison']['result']['close'])->toBe([
            11,
            '12.50000000000000000000',
        ]);
});
