<?php

declare(strict_types=1);

use Binance\Util\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiClients\REST\BinanceApiClient;
use Kraite\Core\Support\ApiClients\REST\BitgetApiClient;
use Kraite\Core\Support\ApiClients\REST\BybitApiClient;
use Kraite\Core\Support\ApiClients\REST\KucoinApiClient;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\RequestInterface;

uses()->group('unit', 'api-client', 'signing', 'retry');

function advanceRetryFreshnessClock(): void
{
    Carbon::setTestNow(now()->addSeconds(11));
}

/**
 * @param  list<Response|callable(RequestInterface, array<string, mixed>): Response>  $responses
 * @return list<array{request: RequestInterface, response: ?Response, error: ?Throwable, options: array<string, mixed>}>
 */
function captureSignedRetryRequests(array $responses, Closure $request): array
{
    $transactions = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($transactions));

    $previousEnvironment = app()->environment();
    $previousMarkerPath = config('kraite.freeze.marker_path');
    $previousTestNow = Carbon::getTestNow();
    $testMarkerPath = storage_path('framework/testing/retry-freshness-unfrozen');

    File::delete($testMarkerPath);
    config()->set('kraite.freeze.marker_path', $testMarkerPath);
    app()->instance(Client::class, new Client(['handler' => $stack]));
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $request();
    } finally {
        app()->detectEnvironment(fn (): string => $previousEnvironment);
        app()->forgetInstance(Client::class);
        config()->set('kraite.freeze.marker_path', $previousMarkerPath);
        Carbon::setTestNow($previousTestNow);
    }

    return $transactions;
}

/** @return array<string, string> */
function retryFreshnessQuery(RequestInterface $request): array
{
    parse_str($request->getUri()->getQuery(), $query);

    return $query;
}

it('re-signs a Binance request after retry backoff', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance Retry Signing Test',
        'recvwindow_margin' => 10_000,
    ]);

    $transactions = captureSignedRetryRequests([
        function (): Response {
            advanceRetryFreshnessClock();

            return new Response(400, [], json_encode([
                'code' => -2013,
                'msg' => 'Order does not exist.',
            ], JSON_THROW_ON_ERROR));
        },
        new Response(200, [], json_encode(['orderId' => '12345', 'status' => 'NEW'], JSON_THROW_ON_ERROR)),
    ], function (): void {
        $client = new BinanceApiClient([
            'url' => 'https://fapi.binance.test',
            'api_key' => 'BINANCE_RETRY_KEY',
            'api_secret' => 'BINANCE_RETRY_SECRET',
        ]);

        $client->signRequest(ApiRequest::make('GET', '/fapi/v1/order', ApiProperties::make([
            'options' => ['symbol' => 'BTCUSDT', 'orderId' => '12345'],
        ])));
    });

    $firstQuery = retryFreshnessQuery($transactions[0]['request']);
    $secondQuery = retryFreshnessQuery($transactions[1]['request']);
    $secondSignature = $secondQuery['signature'];
    unset($secondQuery['signature']);

    expect($transactions)->toHaveCount(2)
        ->and((int) $secondQuery['timestamp'] - (int) $firstQuery['timestamp'])->toBe(11_000)
        ->and($secondSignature)->toBe(hash_hmac(
            'sha256',
            Url::buildQuery($secondQuery),
            'BINANCE_RETRY_SECRET'
        ));
});

it('re-signs a Binance trading request in the URL after retry backoff', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance Trading Retry Signing Test',
        'recvwindow_margin' => 10_000,
    ]);

    $transactions = captureSignedRetryRequests([
        function (): Response {
            advanceRetryFreshnessClock();

            return new Response(503, [], json_encode(['code' => -1007, 'msg' => 'Timeout'], JSON_THROW_ON_ERROR));
        },
        new Response(200, [], json_encode(['orderId' => '54321', 'status' => 'NEW'], JSON_THROW_ON_ERROR)),
    ], function (): void {
        $client = new BinanceApiClient([
            'url' => 'https://fapi.binance.test',
            'api_key' => 'BINANCE_RETRY_KEY',
            'api_secret' => 'BINANCE_RETRY_SECRET',
        ]);

        $client->signRequest(ApiRequest::make('POST', '/fapi/v1/order', ApiProperties::make([
            'options' => [
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'type' => 'LIMIT',
                'quantity' => '0.001',
                'price' => '50000',
            ],
        ])));
    });

    $firstQuery = retryFreshnessQuery($transactions[0]['request']);
    $secondQuery = retryFreshnessQuery($transactions[1]['request']);
    $secondSignature = $secondQuery['signature'];
    unset($secondQuery['signature']);

    expect($transactions)->toHaveCount(2)
        ->and($transactions[1]['request']->getMethod())->toBe('POST')
        ->and((int) $secondQuery['timestamp'] - (int) $firstQuery['timestamp'])->toBe(11_000)
        ->and($secondSignature)->toBe(hash_hmac(
            'sha256',
            Url::buildQuery($secondQuery),
            'BINANCE_RETRY_SECRET'
        ));
});

it('re-signs a Bybit request after retry backoff', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit Retry Signing Test',
        'recvwindow_margin' => 10_000,
    ]);

    $transactions = captureSignedRetryRequests([
        function (): Response {
            advanceRetryFreshnessClock();

            return new Response(503, [], json_encode([
                'retCode' => 10016,
                'retMsg' => 'Service restarting',
            ], JSON_THROW_ON_ERROR));
        },
        new Response(200, [], json_encode(['retCode' => 0, 'retMsg' => 'OK', 'result' => []], JSON_THROW_ON_ERROR)),
    ], function (): void {
        $client = new BybitApiClient([
            'url' => 'https://api.bybit.test',
            'api_key' => 'BYBIT_RETRY_KEY',
            'api_secret' => 'BYBIT_RETRY_SECRET',
        ]);

        $client->signRequest(ApiRequest::make('GET', '/v5/order/realtime', ApiProperties::make([
            'options' => ['category' => 'linear', 'symbol' => 'BTCUSDT'],
        ])));
    });

    $firstRequest = $transactions[0]['request'];
    $secondRequest = $transactions[1]['request'];
    $firstTimestamp = (int) $firstRequest->getHeaderLine('X-BAPI-TIMESTAMP');
    $secondTimestamp = (int) $secondRequest->getHeaderLine('X-BAPI-TIMESTAMP');

    expect($transactions)->toHaveCount(2)
        ->and($secondTimestamp - $firstTimestamp)->toBe(11_000)
        ->and($secondRequest->getHeaderLine('X-BAPI-SIGN'))->toBe(hash_hmac(
            'sha256',
            $secondTimestamp.'BYBIT_RETRY_KEY'.'10000'.$secondRequest->getUri()->getQuery(),
            'BYBIT_RETRY_SECRET'
        ));
});

it('re-signs a KuCoin request after retry backoff', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'kucoin',
        'name' => 'KuCoin Retry Signing Test',
    ]);

    $transactions = captureSignedRetryRequests([
        function (): Response {
            advanceRetryFreshnessClock();

            return new Response(503, [], json_encode([
                'code' => '300000',
                'msg' => 'Internal error',
            ], JSON_THROW_ON_ERROR));
        },
        new Response(200, [], json_encode(['code' => '200000', 'data' => []], JSON_THROW_ON_ERROR)),
    ], function (): void {
        $client = new KucoinApiClient([
            'url' => 'https://api-futures.kucoin.test',
            'api_key' => 'KUCOIN_RETRY_KEY',
            'api_secret' => 'KUCOIN_RETRY_SECRET',
            'passphrase' => 'KUCOIN_RETRY_PASSPHRASE',
        ]);

        $client->signRequest(ApiRequest::make('GET', '/api/v1/order', ApiProperties::make([
            'options' => ['orderId' => '12345'],
        ])));
    });

    $firstRequest = $transactions[0]['request'];
    $secondRequest = $transactions[1]['request'];
    $firstTimestamp = (int) $firstRequest->getHeaderLine('KC-API-TIMESTAMP');
    $secondTimestamp = (int) $secondRequest->getHeaderLine('KC-API-TIMESTAMP');
    $signedEndpoint = $secondRequest->getUri()->getPath().'?'.$secondRequest->getUri()->getQuery();

    expect($transactions)->toHaveCount(2)
        ->and($secondTimestamp - $firstTimestamp)->toBe(11_000)
        ->and($secondRequest->getHeaderLine('KC-API-SIGN'))->toBe(base64_encode(hash_hmac(
            'sha256',
            $secondTimestamp.'GET'.$signedEndpoint,
            'KUCOIN_RETRY_SECRET',
            true
        )));
});

it('keeps Bitget signed retries fresh', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Retry Signing Test',
    ]);

    $requests = [];
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = $request;

        if (count($requests) === 1) {
            advanceRetryFreshnessClock();

            return Http::response(['code' => '45001', 'msg' => 'System maintenance']);
        }

        return Http::response(['code' => '00000', 'msg' => 'success', 'data' => []]);
    });

    $client = new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => 'BITGET_RETRY_KEY',
        'api_secret' => 'BITGET_RETRY_SECRET',
        'passphrase' => 'BITGET_RETRY_PASSPHRASE',
    ]);

    $client->signRequest(ApiRequest::make('GET', '/api/v2/mix/order/detail', ApiProperties::make([
        'options' => ['orderId' => '12345', 'productType' => 'USDC-FUTURES', 'symbol' => 'BTCPERP'],
    ])));

    $firstTimestamp = (int) $requests[0]->header('ACCESS-TIMESTAMP')[0];
    $secondTimestamp = (int) $requests[1]->header('ACCESS-TIMESTAMP')[0];

    expect($requests)->toHaveCount(2)
        ->and($secondTimestamp - $firstTimestamp)->toBe(11_000)
        ->and($requests[0]->header('ACCESS-SIGN')[0])->not->toBe($requests[1]->header('ACCESS-SIGN')[0]);
});
