<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/*
 * Pin the per-order-type effective-display-price logic shared by:
 *   - MapsOrderCancel::computeOrderCancelPrice() (private)
 *   - MapsPlaceOrder::findPlaceOrderPrice() (private)
 *
 * Both branches resolve the `_price` field surfaced by the public
 * resolveOrderCancelResponse / resolvePlaceOrderResponse contracts.
 *
 * Test surface is the public response resolver — feed a Guzzle
 * Response with a JSON body shaped like the Binance order
 * payload and assert the `_price` value across each match arm.
 *
 * These tests serve as the BEFORE-state pin of the
 * `(float) $x > 0` ternaries inside the match expressions.
 * Refactor to BCMath-backed Math::gt() must keep these green.
 */

function buildOrderResponseBody(array $overrides = []): string
{
    $base = [
        'orderId' => 1234567890,
        'symbol' => 'BTCUSDT',
        'status' => 'NEW',
        'price' => '0',
        'avgPrice' => '0',
        'origQty' => '0.001',
        'executedQty' => '0',
        'type' => 'LIMIT',
        'origType' => 'LIMIT',
        'side' => 'BUY',
        'stopPrice' => '0',
        'activatePrice' => '0',
    ];

    return (string) json_encode(array_merge($base, $overrides));
}

function buildResponse(string $body): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], $body);
}

beforeEach(function () {
    $this->mapper = new BinanceApiDataMapper;
});

test('LIMIT order returns the price field unchanged', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'LIMIT',
        'price' => '60000.50',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('60000.50');
});

test('MARKET with positive avgPrice returns avgPrice', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'MARKET',
        'avgPrice' => '60123.45',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('60123.45');
});

test('MARKET with zero avgPrice returns string zero', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'MARKET',
        'avgPrice' => '0',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('0');
});

test('STOP_MARKET returns stopPrice', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'STOP_MARKET',
        'price' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('TRAILING_STOP_MARKET prefers activatePrice when positive', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'TRAILING_STOP_MARKET',
        'activatePrice' => '60500.00',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('60500.00');
});

test('TRAILING_STOP_MARKET falls back to stopPrice when activatePrice is zero', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'TRAILING_STOP_MARKET',
        'activatePrice' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('default branch with positive price returns price', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'EXOTIC_TYPE',
        'price' => '60100.00',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('60100.00');
});

test('default branch with zero price falls back to stopPrice', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'EXOTIC_TYPE',
        'price' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('MapsPlaceOrder:: matches MapsOrderCancel for MARKET avgPrice positive', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'MARKET',
        'avgPrice' => '60123.45',
    ]));

    $result = $this->mapper->resolvePlaceOrderResponse($response);

    expect($result['_price'])->toBe('60123.45');
});

test('MapsPlaceOrder:: matches MapsOrderCancel for TRAILING_STOP_MARKET activatePrice zero', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'TRAILING_STOP_MARKET',
        'activatePrice' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolvePlaceOrderResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('MapsPlaceOrder:: default branch with zero price returns stopPrice', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'EXOTIC_TYPE',
        'price' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolvePlaceOrderResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('MapsOrderModify resolves MARKET avgPrice positive', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'MARKET',
        'avgPrice' => '60123.45',
    ]));

    $result = $this->mapper->resolveOrderModifyResponse($response);

    expect($result['_price'])->toBe('60123.45');
});

test('MapsOrderModify resolves TRAILING_STOP_MARKET activatePrice zero fallback', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'TRAILING_STOP_MARKET',
        'activatePrice' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderModifyResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('MapsOrderModify default branch zero price returns stopPrice', function () {
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'EXOTIC_TYPE',
        'price' => '0',
        'stopPrice' => '59000.00',
    ]));

    $result = $this->mapper->resolveOrderModifyResponse($response);

    expect($result['_price'])->toBe('59000.00');
});

test('precision preserved across high-decimal values (integer-string boundary check)', function () {
    // Sanity test for the BCMath migration target — make sure
    // 0.00000001 (the smallest Binance tick) doesn't get rounded
    // to 0 by any future intermediate float cast.
    $response = buildResponse(buildOrderResponseBody([
        'type' => 'MARKET',
        'avgPrice' => '0.00000001',
    ]));

    $result = $this->mapper->resolveOrderCancelResponse($response);

    expect($result['_price'])->toBe('0.00000001');
});
