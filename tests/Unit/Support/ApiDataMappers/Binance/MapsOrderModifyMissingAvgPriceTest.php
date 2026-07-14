<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/**
 * Binance omits `avgPrice` on modify responses for never-filled orders —
 * a PUT that amends a resting NEW take-profit comes back without the key.
 * The unguarded read fataled the worker AFTER the modify had already
 * landed exchange-side, which failed the WAP take-profit resize
 * (CalculateWapAndModifyProfitOrderJob) and left the TP sized for the
 * pre-WAP fill set while the exchange carried the full averaged quantity
 * (2026-07-13, position #394 FILUSDT — TP stuck at 47.3 qty while the
 * exchange position was 141.9). Same bug class as the cancel-mapper
 * regression pinned by MapsOrderCancelMissingAvgPriceTest.
 */
function buildModifyResponse(array $overrides = []): Response
{
    // Mirrors the real FILUSDT payload: WAP resize of the resting
    // PROFIT-LIMIT after the first DCA fill (47.3 → 141.9 @ 0.7683).
    $body = array_merge([
        'orderId' => 38188617620,
        'symbol' => 'FILUSDT',
        'status' => 'NEW',
        'price' => '0.7683',
        'origQty' => '141.9',
        'executedQty' => '0',
        'type' => 'LIMIT',
        'side' => 'SELL',
        'origType' => 'LIMIT',
    ], $overrides);

    return new Response(200, [], json_encode($body));
}

test('modify response without avgPrice maps to zero average price instead of fataling', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderModifyResponse(buildModifyResponse());

    expect($resolved['average_price'])->toBe('0')
        ->and($resolved['order_id'])->toBe(38188617620)
        ->and($resolved['status'])->toBe('NEW')
        ->and($resolved['price'])->toBe('0.7683')
        ->and($resolved['_price'])->toBe('0.7683')
        ->and($resolved['original_quantity'])->toBe('141.9')
        ->and($resolved['executed_quantity'])->toBe('0')
        ->and($resolved['type'])->toBe('LIMIT')
        ->and($resolved['side'])->toBe('SELL')
        ->and($resolved['original_type'])->toBe('LIMIT')
        ->and($resolved['symbol'])->toBe(['base' => 'FIL', 'quote' => 'USDT']);
});

test('modify response with avgPrice keeps the reported value', function (): void {
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderModifyResponse(buildModifyResponse(['avgPrice' => '0.7690']));

    expect($resolved['average_price'])->toBe('0.7690');
});

test('modify response of a partially filled order maps every field with avgPrice present', function (): void {
    // Partially filled modifies DO carry avgPrice — assert the happy
    // path is untouched by the guard.
    $mapper = new BinanceApiDataMapper;

    $resolved = $mapper->resolveOrderModifyResponse(buildModifyResponse([
        'status' => 'PARTIALLY_FILLED',
        'executedQty' => '50.0',
        'avgPrice' => '0.7684',
    ]));

    expect($resolved['average_price'])->toBe('0.7684')
        ->and($resolved['status'])->toBe('PARTIALLY_FILLED')
        ->and($resolved['executed_quantity'])->toBe('50.0');
});
