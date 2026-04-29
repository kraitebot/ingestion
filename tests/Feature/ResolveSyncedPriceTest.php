<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

function buildOrderWithStoredPrice(string $storedPrice): Order
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'API3']);
    // Pin tick_size + price_precision so api_format_price round-trips
    // the test inputs ('6.93', '0.00000001') unchanged. The factory's
    // randomised tick_size makes formatter-based assertions flaky.
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'API3',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'tick_size' => '0.00000001',
        'price_precision' => 8,
    ]);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    return Order::create([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'NEW',
        'price' => $storedPrice,
        'quantity' => '0',
        'exchange_order_id' => '7777777',
    ]);
}

if (! function_exists('invokeResolveSyncedPrice')) {
    function invokeResolveSyncedPrice(Order $order, mixed $incoming): mixed
    {
        $method = new ReflectionMethod($order, 'resolveSyncedPrice');

        return $method->invoke($order, $incoming);
    }
}

it('preserves the stored price when exchange returns null', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, null))->toBe($order->price);
});

it('preserves the stored price when exchange returns empty string', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, ''))->toBe($order->price);
});

it('preserves the stored price when exchange returns integer 0', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, 0))->toBe($order->price);
});

it('preserves the stored price when exchange returns string "0"', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, '0'))->toBe($order->price);
});

it('preserves the stored price when exchange returns multi-decimal zero', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, '0.00000000'))->toBe($order->price);
});

it('returns the new price when exchange returns a legitimate non-zero value', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, '6.93'))->toBe('6.93');
});

it('returns the new price when exchange returns a tiny positive string', function (): void {
    $order = buildOrderWithStoredPrice('0.42780000');

    expect(invokeResolveSyncedPrice($order, '0.00000001'))->toBe('0.00000001');
});
