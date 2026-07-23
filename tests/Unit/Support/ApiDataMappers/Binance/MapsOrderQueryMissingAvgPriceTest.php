<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/**
 * Same avgPrice-omission bug class as the cancel and modify mappers
 * (MapsOrderCancelMissingAvgPriceTest / MapsOrderModifyMissingAvgPriceTest),
 * guarded proactively on the query path: a GET /fapi/v1/order payload
 * without `avgPrice` must degrade to the plain `price` field instead of
 * fataling the sync worker mid-reconciliation.
 */
function buildQueryResponse(array $overrides = []): Response
{
    $body = array_merge([
        'orderId' => 38188608253,
        'symbol' => 'FILUSDT',
        'status' => 'NEW',
        'price' => '0.7424',
        'origQty' => '94.6',
        'executedQty' => '0',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'origType' => 'LIMIT',
    ], $overrides);

    return new Response(200, [], json_encode($body));
}

test('query response without avgPrice falls back to the plain price field', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderQueryResponse(buildQueryResponse());

    expect($resolved['price'])->toBe('0.7424')
        ->and($resolved['order_id'])->toBe(38188608253)
        ->and($resolved['status'])->toBe('NEW')
        ->and($resolved['quantity'])->toBe('94.6')
        ->and($resolved['type'])->toBe('LIMIT')
        ->and($resolved['side'])->toBe('BUY')
        ->and($resolved['symbol'])->toBe(['base' => 'FIL', 'quote' => 'USDT']);
});

test('query response with a zero avgPrice falls back to the plain price field', function (): void {
    // Resting NEW orders report avgPrice="0" — the pre-existing
    // fallback branch must keep winning over the zero.
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderQueryResponse(buildQueryResponse(['avgPrice' => '0']));

    expect($resolved['price'])->toBe('0.7424');
});

test('query response of a filled order keeps using avgPrice and executedQty', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderQueryResponse(buildQueryResponse([
        'status' => 'FILLED',
        'avgPrice' => '0.74190000',
        'executedQty' => '94.6',
    ]));

    expect($resolved['price'])->toBe('0.74190000')
        ->and($resolved['quantity'])->toBe('94.6')
        ->and($resolved['status'])->toBe('FILLED');
});

test('query response of a partially filled limit keeps stated price and original quantity', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderQueryResponse(buildQueryResponse([
        'status' => 'PARTIALLY_FILLED',
        'price' => '4.105',
        'avgPrice' => '4.10499999',
        'origQty' => '20.99',
        'executedQty' => '9.45',
    ]));

    expect($resolved['price'])->toBe('4.105')
        ->and($resolved['quantity'])->toBe('20.99')
        ->and($resolved['status'])->toBe('PARTIALLY_FILLED');
});

test('query response for STOP_MARKET without avgPrice keeps using stopPrice', function (): void {
    // The STOP_MARKET branch never touched avgPrice — assert the guard
    // did not disturb it.
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderQueryResponse(buildQueryResponse([
        'type' => 'STOP_MARKET',
        'stopPrice' => '0.5194',
        'price' => '0',
    ]));

    expect($resolved['price'])->toBe('0.5194')
        ->and($resolved['quantity'])->toBe('94.6');
});
