<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/**
 * `BinanceApiDataMapper` hedge-mode vs one-way-mode payload contract.
 *
 * Binance Futures supports two account-level position modes:
 *
 *   - HEDGE (`dualSidePosition=true`): every order MUST carry
 *     `positionSide=LONG` or `SHORT`. Open vs close intent is implicit
 *     from the (side, positionSide) combination.
 *   - ONE-WAY (`dualSidePosition=false`): orders MUST omit `positionSide`
 *     (or send `BOTH`). `side` alone determines direction; closing
 *     orders MUST carry `reduceOnly=true` (or `closePosition=true` for
 *     algo SL/TP) otherwise the same `side` reopens in the opposite
 *     direction. Sending `positionSide=LONG/SHORT` returns Binance
 *     error -4061 (POSITION_SIDE_NOT_MATCH).
 *
 * The mapper reads `account.on_hedge_mode` and produces the right
 * payload for each mode. The flag is auto-corrected reactively when
 * the live exchange returns a -4061 family error (catch lives in
 * HandlesApiJobExceptions::handleException).
 *
 * Test matrix below pins one row per (mode × order-type × intent)
 * combination so a future refactor can't silently break either
 * direction.
 */
function buildBinanceContext(bool $hedge, string $orderType, string $direction = 'LONG'): Order
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'BTC']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = $hedge
        ? Account::factory()->hedgeMode()->create(['api_system_id' => $apiSystem->id])
        : Account::factory()->oneWayMode()->create(['api_system_id' => $apiSystem->id]);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => $direction,
        'status' => 'opening',
        'total_limit_orders' => 4, // OrderObserver::enforceOrderLimits gates on this
    ]);

    return Order::create([
        'position_id' => $position->id,
        'type' => $orderType,
        'side' => $direction === 'LONG' ? ($orderType === 'PROFIT-LIMIT' ? 'SELL' : 'BUY') : ($orderType === 'PROFIT-LIMIT' ? 'BUY' : 'SELL'),
        'position_side' => $direction,
        'status' => 'NEW',
        'price' => '50000.00',
        'quantity' => '0.001',
    ]);
}

// =============================================================================
// preparePlaceOrderProperties — regular orders (MARKET, LIMIT, PROFIT-LIMIT)
// =============================================================================

it('hedge: MARKET open carries positionSide=LONG and no reduceOnly', function (): void {
    $order = buildBinanceContext(hedge: true, orderType: 'MARKET');
    $properties = (new BinanceApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.positionSide'))->toBe('LONG');
    expect($properties->has('options.reduceOnly'))->toBeFalse();
});

it('one-way: MARKET open omits positionSide and has no reduceOnly', function (): void {
    $order = buildBinanceContext(hedge: false, orderType: 'MARKET');
    $properties = (new BinanceApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->has('options.positionSide'))->toBeFalse(
        'One-way mode rejects positionSide=LONG/SHORT with -4061; payload must omit it.'
    );
    expect($properties->has('options.reduceOnly'))->toBeFalse(
        'MARKET is opening intent; reduceOnly would prevent the position from opening.'
    );
});

it('one-way: LIMIT (DCA) open omits positionSide and has no reduceOnly', function (): void {
    $order = buildBinanceContext(hedge: false, orderType: 'LIMIT');
    $properties = (new BinanceApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->has('options.positionSide'))->toBeFalse();
    expect($properties->has('options.reduceOnly'))->toBeFalse(
        'DCA limits add to the position; reduceOnly would invert their meaning.'
    );
});

it('hedge: PROFIT-LIMIT (TP) carries positionSide=LONG and no reduceOnly', function (): void {
    $order = buildBinanceContext(hedge: true, orderType: 'PROFIT-LIMIT');
    $properties = (new BinanceApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.positionSide'))->toBe('LONG');
    expect($properties->has('options.reduceOnly'))->toBeFalse(
        'In hedge mode the (side, positionSide) pair already implies close — reduceOnly is redundant.'
    );
});

it('one-way: PROFIT-LIMIT (TP) omits positionSide and sets reduceOnly=true', function (): void {
    $order = buildBinanceContext(hedge: false, orderType: 'PROFIT-LIMIT');
    $properties = (new BinanceApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->has('options.positionSide'))->toBeFalse();
    expect($properties->get('options.reduceOnly'))->toBe(
        'true',
        'In one-way mode a SELL with no reduceOnly opens a SHORT instead of closing the LONG. '
        .'TP must carry reduceOnly=true to close the existing position.'
    );
});

it('one-way: MARKET-CANCEL emergency close omits positionSide and sets reduceOnly=true', function (): void {
    $order = buildBinanceContext(hedge: false, orderType: 'MARKET-CANCEL');
    $properties = (new BinanceApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->has('options.positionSide'))->toBeFalse();
    expect($properties->get('options.reduceOnly'))->toBe('true');
});

// =============================================================================
// preparePlaceAlgoOrderProperties — algo path (STOP-MARKET / TAKE-PROFIT-MARKET)
// =============================================================================

it('hedge: STOP-MARKET (algo) carries positionSide=LONG and closePosition=true', function (): void {
    $order = buildBinanceContext(hedge: true, orderType: 'STOP-MARKET');
    $properties = (new BinanceApiDataMapper)->preparePlaceAlgoOrderProperties($order);

    expect($properties->get('options.positionSide'))->toBe('LONG');
    expect($properties->get('options.closePosition'))->toBe('true');
    expect($properties->has('options.reduceOnly'))->toBeFalse(
        'closePosition and reduceOnly are mutually exclusive per Binance docs.'
    );
});

it('one-way: STOP-MARKET (algo) omits positionSide, keeps closePosition=true, never sets reduceOnly', function (): void {
    $order = buildBinanceContext(hedge: false, orderType: 'STOP-MARKET');
    $properties = (new BinanceApiDataMapper)->preparePlaceAlgoOrderProperties($order);

    expect($properties->has('options.positionSide'))->toBeFalse();
    expect($properties->get('options.closePosition'))->toBe(
        'true',
        'closePosition=true works the same in both modes — closes whatever direction is open.'
    );
    expect($properties->has('options.reduceOnly'))->toBeFalse(
        'closePosition and reduceOnly are mutually exclusive per Binance docs (-4062 if both set).'
    );
});
