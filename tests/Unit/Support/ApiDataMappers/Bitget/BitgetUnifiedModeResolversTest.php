<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;

/**
 * BitGet responds with different envelopes per API generation: classic
 * (v2) nests open orders under `entrustedList` and positions as a plain
 * `data` list; unified (v3) nests both under `data.list` and renames
 * holdSide→posSide / posMode→holdMode on positions. The resolvers must
 * normalise both shapes onto the same downstream contract.
 */
function bitgetJsonResponse(array $payload): Response
{
    return new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
}

it('resolves unified (v3) open orders from data.list with the same enrichment as classic', function (): void {
    $mapper = new BitgetApiDataMapper;

    $orders = $mapper->resolveQueryOpenOrdersResponse(bitgetJsonResponse([
        'code' => '00000',
        'data' => [
            'list' => [
                ['orderId' => 'uta-101', 'symbol' => 'BTCUSDT', 'orderType' => 'limit', 'price' => '40000', 'side' => 'buy'],
            ],
            'cursor' => null,
        ],
    ]));

    expect($orders)->toHaveCount(1)
        ->and($orders[0]['orderId'])->toBe('uta-101')
        ->and($orders[0]['_price'])->toBe('40000')
        ->and($orders[0]['_orderType'])->not->toBe('');
});

it('resolves classic (v2) open orders from entrustedList unchanged', function (): void {
    $mapper = new BitgetApiDataMapper;

    $orders = $mapper->resolveQueryOpenOrdersResponse(bitgetJsonResponse([
        'code' => '00000',
        'data' => [
            'entrustedList' => [
                ['orderId' => 'classic-77', 'symbol' => 'ETHUSDT', 'orderType' => 'limit', 'price' => '2500', 'side' => 'sell'],
            ],
            'endId' => 'classic-77',
        ],
    ]));

    expect($orders)->toHaveCount(1)
        ->and($orders[0]['orderId'])->toBe('classic-77')
        ->and($orders[0]['_price'])->toBe('2500');
});

it('resolves a unified open-orders payload with a null list to an empty array', function (): void {
    $mapper = new BitgetApiDataMapper;

    $orders = $mapper->resolveQueryOpenOrdersResponse(bitgetJsonResponse([
        'code' => '00000',
        'data' => ['list' => null, 'cursor' => null],
    ]));

    expect($orders)->toBe([]);
});

it('normalises unified (v3) positions onto the classic contract including hedge and one-way keys', function (): void {
    $mapper = new BitgetApiDataMapper;

    $positions = $mapper->resolveQueryPositionsResponse(bitgetJsonResponse([
        'code' => '00000',
        'data' => [
            'list' => [
                ['symbol' => 'BTCUSDT', 'total' => '0.5', 'posSide' => 'long', 'holdMode' => 'hedge_mode'],
                ['symbol' => 'ETHUSDT', 'total' => '2', 'posSide' => 'short', 'holdMode' => 'one_way_mode'],
                ['symbol' => 'XRPUSDT', 'total' => '0', 'posSide' => 'long', 'holdMode' => 'hedge_mode'],
            ],
        ],
    ]));

    expect($positions)->toHaveKeys(['BTCUSDT:LONG', 'ETHUSDT:BOTH'])
        ->and($positions)->toHaveCount(2)
        ->and($positions['BTCUSDT:LONG']['side'])->toBe('long')
        ->and($positions['BTCUSDT:LONG']['size'])->toBe(0.5)
        ->and($positions['BTCUSDT:LONG']['positionAmt'])->toBe(0.5)
        ->and($positions['ETHUSDT:BOTH']['side'])->toBe('short')
        ->and($positions['ETHUSDT:BOTH']['positionAmt'])->toBe(-2.0);
});

it('still resolves classic (v2) positions from the plain data list', function (): void {
    $mapper = new BitgetApiDataMapper;

    $positions = $mapper->resolveQueryPositionsResponse(bitgetJsonResponse([
        'code' => '00000',
        'data' => [
            ['symbol' => 'BTCUSDT', 'total' => '1.5', 'holdSide' => 'short', 'posMode' => 'hedge_mode'],
        ],
    ]));

    expect($positions)->toHaveKey('BTCUSDT:SHORT')
        ->and($positions['BTCUSDT:SHORT']['positionAmt'])->toBe(-1.5);
});

it('resolves a unified positions payload with a null list to an empty array', function (): void {
    $mapper = new BitgetApiDataMapper;

    $positions = $mapper->resolveQueryPositionsResponse(bitgetJsonResponse([
        'code' => '00000',
        'data' => ['list' => null],
    ]));

    expect($positions)->toBe([]);
});
