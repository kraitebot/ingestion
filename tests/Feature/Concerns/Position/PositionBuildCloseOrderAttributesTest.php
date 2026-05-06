<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the local-truth close-order builder on Position.
 *
 * After Position #577 (TONUSDT, 2026-05-06) — where Binance's REST
 * positions endpoint lagged the WS trade ledger by ~17s during a
 * cancel cascade and `apiClose()`'s exchange query returned an empty
 * list, leaving `apiClose()`'s foreach iterating zero rows and the
 * position naked on the exchange — the close-order shape is now
 * derived from local DB truth: the sum of FILLED MARKET + LIMIT
 * quantity, the position's own direction, and `position_side` for
 * hedge-mode payload encoding.
 *
 * One-way Binance still routes correctly because
 * `MapsPlaceOrder::preparePlaceOrderProperties` reads
 * `account->isHedgeMode()` and either injects positionSide (hedge)
 * or sets reduceOnly=true (one-way) — so the same Order row works
 * in both modes, regardless of what `position_side` the row carries.
 */
function buildPositionForCloseAttributes(string $direction, string $exchange = 'binance'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $exchange,
        'name' => mb_ucfirst($exchange),
    ]);

    $symbol = Symbol::factory()->create(['token' => 'TON']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'TON',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'TONUSDT',
        'direction' => $direction,
        'status' => 'cancelling',
        'total_limit_orders' => 4,
    ]);
}

function attachOrderTo(Position $position, string $type, string $status, string $quantity, string $side, string $positionSide): void
{
    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => (string) random_int(1000000, 9999999),
        'type' => $type,
        'side' => $side,
        'position_side' => $positionSide,
        'status' => $status,
        'reference_status' => $status,
        'price' => '2.20000000',
        'reference_price' => '2.20000000',
        'quantity' => $quantity,
        'reference_quantity' => $quantity,
        'is_algo' => false,
    ]));
}

it('returns LONG-close shape when only the MARKET entry has filled', function (): void {
    $position = buildPositionForCloseAttributes('LONG');
    attachOrderTo($position, type: 'MARKET', status: 'FILLED', quantity: '12.00000000', side: 'BUY', positionSide: 'LONG');

    $attrs = $position->buildCloseOrderAttributes();

    expect($attrs)->toBe([
        'type' => 'MARKET-CANCEL',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '12.00000000',
        'position_id' => $position->id,
    ]);
});

it('returns SHORT-close shape with MARKET + filled LIMIT quantity summed', function (): void {
    $position = buildPositionForCloseAttributes('SHORT');
    attachOrderTo($position, type: 'MARKET', status: 'FILLED', quantity: '15.00000000', side: 'SELL', positionSide: 'SHORT');
    attachOrderTo($position, type: 'LIMIT', status: 'FILLED', quantity: '30.00000000', side: 'SELL', positionSide: 'SHORT');

    $attrs = $position->buildCloseOrderAttributes();

    expect($attrs['type'])->toBe('MARKET-CANCEL');
    expect($attrs['side'])->toBe('BUY');
    expect($attrs['position_side'])->toBe('SHORT');
    expect($attrs['quantity'])->toBe('45.00000000');
    expect($attrs['position_id'])->toBe($position->id);
});

it('ignores LIMIT rungs that have not filled', function (): void {
    $position = buildPositionForCloseAttributes('LONG');
    attachOrderTo($position, type: 'MARKET', status: 'FILLED', quantity: '12.00000000', side: 'BUY', positionSide: 'LONG');
    attachOrderTo($position, type: 'LIMIT', status: 'NEW', quantity: '24.00000000', side: 'BUY', positionSide: 'LONG');
    attachOrderTo($position, type: 'LIMIT', status: 'CANCELLED', quantity: '48.00000000', side: 'BUY', positionSide: 'LONG');
    attachOrderTo($position, type: 'LIMIT', status: 'PARTIALLY_FILLED', quantity: '96.00000000', side: 'BUY', positionSide: 'LONG');

    $attrs = $position->buildCloseOrderAttributes();

    expect($attrs['quantity'])->toBe('12.00000000');
});

it('returns null when no FILLED orders exist (nothing to close)', function (): void {
    $position = buildPositionForCloseAttributes('LONG');
    attachOrderTo($position, type: 'MARKET', status: 'NEW', quantity: '12.00000000', side: 'BUY', positionSide: 'LONG');

    expect($position->buildCloseOrderAttributes())->toBeNull();
});
