<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiClients\REST\BybitApiClient;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

/**
 * Bybit V5 attaches the per-API-key (UID) rate-limit bucket whenever the
 * request carries X-BAPI-API-KEY, even on public market endpoints. Because
 * that bucket is far smaller than the public IP bucket, sending the key on
 * /v5/market/* burns the UID quota and trips retCode 10006.
 *
 * Public requests must therefore be sent WITHOUT any auth header. Signed
 * requests must continue to carry the full X-BAPI-API-KEY + signature stack.
 */
uses(RefreshDatabase::class)->group('unit', 'bybit', 'api-client');

beforeEach(function () {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
        'recvwindow_margin' => 1000,
    ]);

    $this->client = new BybitApiClient([
        'url' => 'https://api.bybit.test',
        'api_key' => 'TESTKEY',
        'api_secret' => 'TESTSECRET',
    ]);
});

it('does not send X-BAPI-API-KEY on public market kline requests', function () {
    Http::fake([
        '*' => Http::response([
            'retCode' => 0,
            'retMsg' => 'OK',
            'result' => ['list' => []],
            'retExtInfo' => [],
            'time' => 0,
        ], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('options.symbol', 'BTCUSDT');
    $properties->set('options.category', 'linear');
    $properties->set('options.interval', '60');

    $apiRequest = ApiRequest::make('GET', '/v5/market/kline', $properties);

    $this->client->publicRequest($apiRequest);

    Http::assertSent(function ($request) {
        return ! $request->hasHeader('X-BAPI-API-KEY');
    });
});

it('sends X-BAPI-API-KEY on signed account requests', function () {
    Http::fake([
        '*' => Http::response([
            'retCode' => 0,
            'retMsg' => 'OK',
            'result' => ['list' => []],
            'retExtInfo' => [],
            'time' => 0,
        ], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('options.accountType', 'UNIFIED');

    $apiRequest = ApiRequest::make('GET', '/v5/account/wallet-balance', $properties);

    $this->client->signRequest($apiRequest);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-BAPI-API-KEY')
            && $request->header('X-BAPI-API-KEY') === ['TESTKEY']
            && $request->hasHeader('X-BAPI-SIGN')
            && $request->hasHeader('X-BAPI-TIMESTAMP')
            && $request->hasHeader('X-BAPI-RECV-WINDOW');
    });
});
