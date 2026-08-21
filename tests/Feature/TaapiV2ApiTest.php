<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Apis\REST\TaapiApi;
use Kraite\Core\Support\HeaderSanitizer;
use Kraite\Core\Support\Throttlers\TaapiThrottler;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;

beforeEach(function (): void {
    ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance test provider',
        'is_exchange' => true,
        'is_active' => true,
    ]);

    ApiSystem::factory()->create([
        'canonical' => 'taapi',
        'name' => 'TAAPI test provider',
        'is_exchange' => false,
        'is_active' => true,
    ]);

    config()->set('kraite.throttlers.taapi.requests_per_window', 15_000);
    config()->set('kraite.throttlers.taapi.window_seconds', 1);
    config()->set('kraite.throttlers.taapi.min_delay_between_requests_ms', 0);
    config()->set('kraite.throttlers.taapi.safety_threshold', 1.0);
    TaapiThrottler::reset();
});

function taapiV2TestCredentials(string $token = 'taapi-v2-test-token'): ApiCredentials
{
    return ApiCredentials::make([
        'taapi_secret' => 'taapi-legacy-test-secret',
        'taapi_v2_token' => $token,
    ]);
}

function taapiV2BulkProperties(): ApiProperties
{
    return new ApiProperties([
        'constructs' => [[
            'exchange' => 'binancefutures',
            'symbol' => 'BTC/USDT',
            'interval' => '1h',
            'indicators' => [
                [
                    'id' => 'binancefutures|BTCUSDT|candle-comparison',
                    'indicator' => 'candle',
                    'results' => 2,
                    'backtrack' => 1,
                ],
                [
                    'id' => 'binancefutures|BTCUSDT|ema-40',
                    'indicator' => 'ema',
                    'period' => 40,
                    'results' => 2,
                    'backtrack' => 1,
                ],
            ],
        ]],
    ]);
}

function taapiV2Candles(): array
{
    return [
        ['timestamp' => 1_700_000_000, 'open' => 100, 'high' => 110, 'low' => 90, 'close' => 105, 'volume' => 1000],
        ['timestamp' => 1_700_003_600, 'open' => 105, 'high' => 115, 'low' => 95, 'close' => 110, 'volume' => 1100],
        ['timestamp' => 1_700_007_200, 'open' => 110, 'high' => 120, 'low' => 100, 'close' => 115, 'volume' => 1200],
        ['timestamp' => 1_700_010_800, 'open' => 115, 'high' => 125, 'low' => 105, 'close' => 120, 'volume' => 1300],
    ];
}

function taapiV2BinanceKlines(): array
{
    return [
        [1_700_000_000_000, '100', '110', '90', '105', '1000'],
        [1_700_003_600_000, '105', '115', '95', '110', '1100'],
        [1_700_007_200_000, '110', '120', '100', '115', '1200'],
        [1_700_010_800_000, '115', '125', '105', '120', '1300'],
    ];
}

it('keeps the legacy TAAPI request contract available for rollback', function (): void {
    config()->set('kraite.api.taapi.driver', 'legacy');
    $legacyResponse = [
        'data' => [[
            'id' => 'binancefutures|BTCUSDT|ema-40',
            'indicator' => 'ema',
            'result' => ['value' => [101.5, 102.5]],
            'errors' => [],
        ]],
    ];
    Http::fake([
        'https://api.taapi.io/bulk' => Http::response($legacyResponse),
    ]);

    expect(ApiRequestLog::query()->where('path', '/bulk')->exists())->toBeFalse();

    $response = (new TaapiApi(taapiV2TestCredentials()))
        ->getBulkIndicatorsValues(taapiV2BulkProperties());

    expect(json_decode((string) $response->getBody(), true))->toBe($legacyResponse)
        ->and(ApiRequestLog::query()->where('path', '/bulk')->count())->toBe(1)
        ->and(ApiRequestLog::query()->whereIn('path', ['/candles', '/bulk-candles'])->exists())->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(static function (Request $request): bool {
        return $request->url() === 'https://api.taapi.io/bulk'
            && $request->data()['secret'] === 'taapi-legacy-test-secret'
            && $request->data()['construct'][0]['symbol'] === 'BTC/USDT'
            && ! $request->hasHeader('Authorization');
    });
});

it('maps exchange candles and one v2 bulk-candles response back to the legacy data contract', function (): void {
    config()->set('kraite.api.taapi.driver', 'v2');
    config()->set('kraite.api_request_logs.retain_all_bodies', true);
    $token = 'taapi-v2-test-token';
    config()->set('kraite.api.credentials.taapi.v2_token', $token);
    Http::fake([
        'https://fapi.binance.com/fapi/v1/klines*' => Http::response(taapiV2BinanceKlines()),
        'https://v2.taapi.io/bulk-candles' => Http::response([
            'construct_0' => [
                'indicator_1' => [
                    'result' => ['value' => [108.25, 112.75]],
                    'errors' => [],
                ],
            ],
        ]),
    ]);

    expect(ApiRequestLog::query()->whereIn('path', ['/fapi/v1/klines', '/bulk-candles'])->exists())->toBeFalse();

    $response = (new TaapiApi(ApiCredentials::make(['taapi_secret' => 'legacy-only'])))
        ->getBulkIndicatorsValues(taapiV2BulkProperties());

    expect(json_decode((string) $response->getBody(), true))->toBe([
        'data' => [
            [
                'id' => 'binancefutures|BTCUSDT|candle-comparison',
                'indicator' => 'candle',
                'result' => [
                    'timestamp' => [1_700_003_600, 1_700_007_200],
                    'open' => [105, 110],
                    'high' => [115, 120],
                    'low' => [95, 100],
                    'close' => [110, 115],
                    'volume' => [1100, 1200],
                ],
                'errors' => [],
            ],
            [
                'id' => 'binancefutures|BTCUSDT|ema-40',
                'indicator' => 'ema',
                'result' => ['value' => [108.25, 112.75]],
                'errors' => [],
            ],
        ],
    ]);

    $logs = ApiRequestLog::query()
        ->whereIn('path', ['/fapi/v1/klines', '/bulk-candles'])
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[1]->http_headers_sent['Authorization'])->toBe(HeaderSanitizer::REDACTION_PLACEHOLDER)
        ->and(json_encode($logs->toArray()))->not->toContain($token)
        ->and(ApiRequestLog::query()->whereIn('path', ['/bulk', '/candles'])->exists())->toBeFalse()
        ->and(ApiRequestLog::query()->where('path', '/bulk-candles')->count())->toBe(1);

    Http::assertSentCount(2);
    Http::assertSent(static function (Request $request): bool {
        return str_starts_with($request->url(), 'https://fapi.binance.com/fapi/v1/klines')
            && $request->data() === [
                'symbol' => 'BTCUSDT',
                'interval' => '1h',
                'limit' => 200,
            ]
            && ! $request->hasHeader('Authorization');
    });
    Http::assertSent(static function (Request $request) use ($token): bool {
        return $request->url() === 'https://v2.taapi.io/bulk-candles'
            && $request->data()['constructs'][0]['id'] === 'construct_0'
            && $request->data()['constructs'][0]['indicators'] === [[
                'id' => 'indicator_1',
                'indicator' => 'ema',
                'period' => 40,
                'results' => 2,
                'backtrack' => 1,
            ]]
            && $request->data()['constructs'][0]['candles'] === taapiV2Candles()
            && $request->header('Authorization')[0] === 'Bearer '.$token
            && ! array_key_exists('secret', $request->data());
    });
});

it('uses Binance spot candles while keeping one TAAPI request', function (): void {
    config()->set('kraite.api.taapi.driver', 'v2');
    $properties = taapiV2BulkProperties();
    $constructs = $properties->get('constructs');
    $constructs[0]['exchange'] = 'binance';
    $properties->set('constructs', $constructs);
    Http::fake([
        'https://data-api.binance.vision/api/v3/klines*' => Http::response(taapiV2BinanceKlines()),
        'https://v2.taapi.io/bulk-candles' => Http::response([
            'construct_0' => [
                'indicator_1' => ['result' => ['value' => [108.25, 112.75]], 'errors' => []],
            ],
        ]),
    ]);

    (new TaapiApi(taapiV2TestCredentials()))->getBulkIndicatorsValues($properties);

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => str_starts_with(
        $request->url(),
        'https://data-api.binance.vision/api/v3/klines',
    ));
    expect(ApiRequestLog::query()->where('path', '/bulk-candles')->count())->toBe(1)
        ->and(ApiRequestLog::query()->where('path', '/candles')->exists())->toBeFalse();
});

it('accepts pre-supplied candles for a one-call live smoke path', function (): void {
    config()->set('kraite.api.taapi.driver', 'v2');
    $properties = taapiV2BulkProperties();
    $constructs = $properties->get('constructs');
    $constructs[0]['candles'] = taapiV2Candles();
    $properties->set('constructs', $constructs);
    Http::fake([
        'https://v2.taapi.io/bulk-candles' => Http::response([
            'construct_0' => [
                'indicator_1' => ['result' => ['value' => [108.25, 112.75]], 'errors' => []],
            ],
        ]),
    ]);

    $response = (new TaapiApi(taapiV2TestCredentials()))->getBulkIndicatorsValues($properties);

    expect(json_decode((string) $response->getBody(), true)['data'])->toHaveCount(2)
        ->and(ApiRequestLog::query()->where('path', '/bulk-candles')->count())->toBe(1)
        ->and(ApiRequestLog::query()->whereIn('path', ['/fapi/v1/klines', '/api/v3/klines', '/candles'])->exists())
        ->toBeFalse();
    Http::assertSentCount(1);
});

it('does not send traffic when v2 is selected without a token', function (): void {
    config()->set('kraite.api.taapi.driver', 'v2');
    config()->set('kraite.api.credentials.taapi.v2_token', null);

    expect(fn () => new TaapiApi(ApiCredentials::make(['taapi_secret' => 'legacy-only'])))
        ->toThrow(RuntimeException::class, 'TAAPI v2 token is not configured.');

    expect(ApiRequestLog::query()->whereIn('path', ['/candles', '/bulk-candles'])->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('does not poll a pending v2 response beyond the one-call budget', function (): void {
    config()->set('kraite.api.taapi.driver', 'v2');
    Http::fake([
        'https://v2.taapi.io/indicator/rsi*' => Http::response(['status' => 'pending'], 202),
    ]);
    $properties = new ApiProperties([
        'options' => [
            'endpoint' => 'rsi',
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'interval' => '1h',
            'period' => 14,
            'results' => 1,
        ],
    ]);

    expect(ApiRequestLog::query()->where('path', '/indicator/rsi')->exists())->toBeFalse();

    expect(fn () => (new TaapiApi(taapiV2TestCredentials()))->getIndicatorValues($properties))
        ->toThrow(RuntimeException::class, 'one-call budget');

    expect(ApiRequestLog::query()->where('path', '/indicator/rsi')->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->data() === [
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'period' => 14,
        'results' => 1,
        'timeframe' => '1h',
    ]);
});

it('stops before TAAPI when exchange candles are malformed', function (): void {
    config()->set('kraite.api.taapi.driver', 'v2');
    Http::fake([
        'https://fapi.binance.com/fapi/v1/klines*' => Http::response([['malformed']]),
    ]);

    expect(fn () => (new TaapiApi(taapiV2TestCredentials()))
        ->getBulkIndicatorsValues(taapiV2BulkProperties()))
        ->toThrow(RuntimeException::class, 'malformed kline');

    expect(ApiRequestLog::query()->where('path', '/fapi/v1/klines')->count())->toBe(1)
        ->and(ApiRequestLog::query()->where('path', '/bulk-candles')->exists())->toBeFalse();
    Http::assertSentCount(1);
});

it('rejects invalid driver names and empty bulk constructs before sending traffic', function (): void {
    config()->set('kraite.api.taapi.driver', 'future');

    expect(fn () => new TaapiApi(taapiV2TestCredentials()))
        ->toThrow(InvalidArgumentException::class, 'Unsupported TAAPI driver "future"');

    config()->set('kraite.api.taapi.driver', 'v2');
    expect(fn () => (new TaapiApi(taapiV2TestCredentials()))
        ->getBulkIndicatorsValues(new ApiProperties(['constructs' => []])))
        ->toThrow(InvalidArgumentException::class, 'non-empty constructs list');

    expect(ApiRequestLog::query()->whereIn('path', ['/bulk', '/candles', '/bulk-candles'])->exists())->toBeFalse();
    Http::assertNothingSent();
});
