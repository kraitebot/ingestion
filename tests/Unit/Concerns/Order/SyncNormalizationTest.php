<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Proves the apiSync* path can never leak a non-tick-aligned price or a
 * non-lot-aligned quantity into `orders`. The normalization lives in the
 * protected resolveSyncedPrice / resolveSyncedQuantity helpers on the
 * InteractsWithApis trait — we invoke them via reflection against a stub
 * Order so the test stays pure (no exchange calls, no DB writes).
 */
function buildSyncNormalizationStub(float|string|null $stored = '0.0456000'): Order
{
    $symbol = new ExchangeSymbol;
    $symbol->tick_size = '0.00001';
    $symbol->price_precision = 5;
    $symbol->step_size = '1';
    $symbol->quantity_precision = 0;

    $position = new Position;
    $position->setRelation('exchangeSymbol', $symbol);

    $order = new Order;
    $order->price = $stored;
    $order->quantity = '415';
    $order->setRelation('position', $position);

    return $order;
}

function invokeResolveSyncedPrice(Order $order, mixed $incoming): mixed
{
    $method = new ReflectionMethod($order, 'resolveSyncedPrice');

    return $method->invoke($order, $incoming);
}

function invokeResolveSyncedQuantity(Order $order, mixed $incoming): mixed
{
    $method = new ReflectionMethod($order, 'resolveSyncedQuantity');

    return $method->invoke($order, $incoming);
}

it('preserves the stored price when the exchange echoes null or zero', function (): void {
    $order = buildSyncNormalizationStub('0.04560');
    $stored = $order->price;

    expect(invokeResolveSyncedPrice($order, null))->toBe($stored);
    expect(invokeResolveSyncedPrice($order, '0'))->toBe($stored);
    expect(invokeResolveSyncedPrice($order, '0.00000000'))->toBe($stored);
});

it('floors a positive incoming price onto the symbol tick grid', function (): void {
    $order = buildSyncNormalizationStub('0.04560');

    expect(invokeResolveSyncedPrice($order, '0.045678'))->toBe('0.04567');
    expect(invokeResolveSyncedPrice($order, '0.04560'))->toBe('0.0456');
    expect(invokeResolveSyncedPrice($order, '0.0456000'))->toBe('0.0456');
});

it('preserves the stored quantity when the exchange omits the field', function (): void {
    $order = buildSyncNormalizationStub();

    expect(invokeResolveSyncedQuantity($order, null))->toBe('415');
    expect(invokeResolveSyncedQuantity($order, ''))->toBe('415');
    expect(invokeResolveSyncedQuantity($order, 'not-a-number'))->toBe('415');
});

it('aligns a numeric incoming quantity to the symbol lot grid', function (): void {
    $order = buildSyncNormalizationStub();

    expect(invokeResolveSyncedQuantity($order, '83.42'))->toBe('83');
    expect(invokeResolveSyncedQuantity($order, '415'))->toBe('415');
});

it('keeps a legitimate zero quantity (Binance algo executedQty for unfilled SL)', function (): void {
    $order = buildSyncNormalizationStub();

    expect(invokeResolveSyncedQuantity($order, '0'))->toBe('0');
    expect(invokeResolveSyncedQuantity($order, 0))->toBe('0');
});
