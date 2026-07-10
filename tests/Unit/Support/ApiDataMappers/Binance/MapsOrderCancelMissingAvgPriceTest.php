<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/**
 * Binance omits `avgPrice` on cancel responses for never-filled orders.
 * The unguarded read fataled the worker AFTER the cancel had already
 * landed exchange-side, which failed CancelPositionOpenOrdersJob inside
 * the close chain and orphaned the position's remaining DCA ladder on
 * the exchange (2026-07-10 night watch, position #176 SKYUSDT — three
 * ownerless SELL LIMITs left resting after a TP-fill close).
 */
function buildCancelResponse(array $overrides = []): Response
{
    $body = array_merge([
        'orderId' => 493488448,
        'symbol' => 'SKYUSDT',
        'status' => 'CANCELED',
        'price' => '0.06702',
        'origQty' => '2040',
        'executedQty' => '0',
        'type' => 'LIMIT',
        'side' => 'SELL',
        'origType' => 'LIMIT',
    ], $overrides);

    return new Response(200, [], json_encode($body));
}

test('cancel response without avgPrice maps to zero average price', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderCancelResponse(buildCancelResponse());

    expect($resolved['average_price'])->toBe('0')
        ->and($resolved['order_id'])->toBe(493488448)
        ->and($resolved['status'])->toBe('CANCELED')
        ->and($resolved['_price'])->toBe('0.06702');
});

test('cancel response with avgPrice keeps the reported value', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderCancelResponse(buildCancelResponse(['avgPrice' => '0.06810']));

    expect($resolved['average_price'])->toBe('0.06810');
});
