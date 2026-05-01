<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;

/*
|--------------------------------------------------------------------------
| Bitget hedge vs one-way mode mapper contracts
|--------------------------------------------------------------------------
|
| Pins the request-payload and response-keying contracts that diverge
| between hedge mode (`accounts.on_hedge_mode = true`) and one-way mode
| (`accounts.on_hedge_mode = false`).
|
| Bitget V2 contract per https://www.bitget.com/api-doc:
|   HEDGE   — every order/leverage/TPSL call carries `posSide`/`tradeSide`
|             /`holdSide` to disambiguate the LONG vs SHORT slot.
|   ONE-WAY — those params are rejected (40034 / "param error" / "the
|             order type for unilateral position must also be the
|             unilateral position type"); closing-intent orders express
|             intent via `reduceOnly: YES`.
|
| Without these tests, every regression on the mapper's mode branching
| would silently break one of the two modes — and we maintain BOTH
| (hedge = current prod default; one-way = supported for new accounts).
*/

function bitgetTestPosition(bool $hedgeMode = true, string $direction = 'LONG'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'BitGet',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'BTC']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $accountFactory = Account::factory()->state([
        'api_system_id' => $apiSystem->id,
    ]);

    $accountFactory = $hedgeMode ? $accountFactory->hedgeMode() : $accountFactory->oneWayMode();

    return Position::factory()->create([
        'account_id' => $accountFactory->create()->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => $direction,
        'total_limit_orders' => 4,
    ]);
}

function bitgetTestOrder(Position $position, array $attributes = []): Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'exchange_order_id' => '1234567890',
        'client_order_id' => 'test-order-'.uniqid(),
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '40000.00',
        'quantity' => '0.001',
        'position_side' => $position->direction,
    ], $attributes));
}

function bitgetMockResponse(array $data, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
}

// =============================================================================
// MapsPlaceOrder — request payload branching
// =============================================================================

test('place-order in HEDGE mode carries posSide+tradeSide and omits reduceOnly', function () {
    $position = bitgetTestPosition(hedgeMode: true);
    $order = bitgetTestOrder($position, ['type' => 'LIMIT']);

    $properties = (new BitgetApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.tradeSide'))->toBe('open');
    expect($properties->get('options.posSide'))->toBe('long');
    expect($properties->get('options.reduceOnly'))->toBeNull();
});

test('place-order in HEDGE mode carries tradeSide=close on closing intent', function () {
    $position = bitgetTestPosition(hedgeMode: true);
    $order = bitgetTestOrder($position, ['type' => 'PROFIT-LIMIT']);

    $properties = (new BitgetApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.tradeSide'))->toBe('close');
    expect($properties->get('options.posSide'))->toBe('long');
    expect($properties->get('options.reduceOnly'))->toBeNull();
});

test('place-order in ONE-WAY mode opening order omits posSide/tradeSide/reduceOnly', function () {
    $position = bitgetTestPosition(hedgeMode: false);
    $order = bitgetTestOrder($position, ['type' => 'LIMIT']);

    $properties = (new BitgetApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.tradeSide'))->toBeNull();
    expect($properties->get('options.posSide'))->toBeNull();
    expect($properties->get('options.reduceOnly'))->toBeNull();
});

test('place-order in ONE-WAY mode PROFIT-LIMIT carries reduceOnly=YES', function () {
    $position = bitgetTestPosition(hedgeMode: false);
    $order = bitgetTestOrder($position, ['type' => 'PROFIT-LIMIT']);

    $properties = (new BitgetApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.reduceOnly'))->toBe('YES');
    expect($properties->get('options.tradeSide'))->toBeNull();
    expect($properties->get('options.posSide'))->toBeNull();
});

test('place-order in ONE-WAY mode MARKET-CANCEL carries reduceOnly=YES', function () {
    $position = bitgetTestPosition(hedgeMode: false);
    $order = bitgetTestOrder($position, ['type' => 'MARKET-CANCEL']);

    $properties = (new BitgetApiDataMapper)->preparePlaceOrderProperties($order);

    expect($properties->get('options.reduceOnly'))->toBe('YES');
});

// =============================================================================
// MapsPlacePlanOrder — STOP-MARKET / trigger orders
// =============================================================================

test('plan-order in HEDGE mode carries tradeSide', function () {
    $position = bitgetTestPosition(hedgeMode: true);
    $order = bitgetTestOrder($position, ['type' => 'STOP-MARKET']);

    $properties = (new BitgetApiDataMapper)->preparePlacePlanOrderProperties($order);

    expect($properties->get('options.tradeSide'))->toBe('close');
    expect($properties->get('options.reduceOnly'))->toBeNull();
});

test('plan-order in ONE-WAY mode omits tradeSide and carries reduceOnly=YES', function () {
    $position = bitgetTestPosition(hedgeMode: false);
    $order = bitgetTestOrder($position, ['type' => 'STOP-MARKET']);

    $properties = (new BitgetApiDataMapper)->preparePlacePlanOrderProperties($order);

    expect($properties->get('options.tradeSide'))->toBeNull();
    expect($properties->get('options.reduceOnly'))->toBe('YES');
});

// =============================================================================
// MapsPlacePosTpsl — position-level TP/SL attach (paired)
// =============================================================================

test('place-pos-tpsl in HEDGE mode carries holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: true, direction: 'SHORT');

    $properties = (new BitgetApiDataMapper)
        ->preparePlacePosTpslProperties($position, '50000', '30000');

    expect($properties->get('options.holdSide'))->toBe('short');
});

test('place-pos-tpsl in ONE-WAY mode omits holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: false);

    $properties = (new BitgetApiDataMapper)
        ->preparePlacePosTpslProperties($position, '50000', '30000');

    expect($properties->get('options.holdSide'))->toBeNull();
});

// =============================================================================
// MapsPlaceTpslOrder — single TP or SL recreate
// =============================================================================

test('place-tpsl-order in HEDGE mode carries holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: true);
    $order = bitgetTestOrder($position, ['type' => 'STOP-MARKET']);

    $properties = (new BitgetApiDataMapper)->preparePlaceTpslOrderProperties($order);

    expect($properties->get('options.holdSide'))->toBe('long');
});

test('place-tpsl-order in ONE-WAY mode omits holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: false);
    $order = bitgetTestOrder($position, ['type' => 'STOP-MARKET']);

    $properties = (new BitgetApiDataMapper)->preparePlaceTpslOrderProperties($order);

    expect($properties->get('options.holdSide'))->toBeNull();
});

// =============================================================================
// MapsModifyTpsl — TP/SL price modify
// =============================================================================

test('modify-tpsl in HEDGE mode carries holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: true);
    $order = bitgetTestOrder($position, ['type' => 'STOP-MARKET']);

    $properties = (new BitgetApiDataMapper)
        ->prepareModifyTpslOrderProperties($order, '32000');

    expect($properties->get('options.holdSide'))->toBe('long');
});

test('modify-tpsl in ONE-WAY mode omits holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: false);
    $order = bitgetTestOrder($position, ['type' => 'STOP-MARKET']);

    $properties = (new BitgetApiDataMapper)
        ->prepareModifyTpslOrderProperties($order, '32000');

    expect($properties->get('options.holdSide'))->toBeNull();
});

// =============================================================================
// MapsTokenLeverageRatios — set-leverage
// =============================================================================

test('set-leverage in HEDGE mode carries holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: true, direction: 'SHORT');

    $properties = (new BitgetApiDataMapper)
        ->prepareUpdateLeverageRatioProperties($position, 20);

    expect($properties->get('options.holdSide'))->toBe('short');
});

test('set-leverage in ONE-WAY mode omits holdSide', function () {
    $position = bitgetTestPosition(hedgeMode: false);

    $properties = (new BitgetApiDataMapper)
        ->prepareUpdateLeverageRatioProperties($position, 20);

    expect($properties->get('options.holdSide'))->toBeNull();
});

// =============================================================================
// MapsPositionsQuery — response keying mirrors Binance for consumer parity
// =============================================================================

test('positions response in HEDGE mode keys by symbol:LONG and symbol:SHORT', function () {
    $mapper = new BitgetApiDataMapper;

    $response = bitgetMockResponse([
        'code' => '00000',
        'data' => [
            [
                'symbol' => 'BTCUSDT',
                'holdSide' => 'long',
                'posMode' => 'hedge_mode',
                'total' => '0.5',
                'available' => '0.5',
                'openPriceAvg' => '40000',
            ],
            [
                'symbol' => 'BTCUSDT',
                'holdSide' => 'short',
                'posMode' => 'hedge_mode',
                'total' => '0.3',
                'available' => '0.3',
                'openPriceAvg' => '41000',
            ],
        ],
    ]);

    $result = $mapper->resolveQueryPositionsResponse($response);

    expect($result)->toHaveKey('BTCUSDT:LONG');
    expect($result)->toHaveKey('BTCUSDT:SHORT');
    expect($result['BTCUSDT:LONG']['positionSide'])->toBe('LONG');
    expect($result['BTCUSDT:SHORT']['positionSide'])->toBe('SHORT');
    expect($result['BTCUSDT:LONG']['positionAmt'])->toBe(0.5);
    expect($result['BTCUSDT:SHORT']['positionAmt'])->toBe(-0.3);
});

test('positions response in ONE-WAY mode keys by symbol:BOTH', function () {
    $mapper = new BitgetApiDataMapper;

    $response = bitgetMockResponse([
        'code' => '00000',
        'data' => [
            [
                'symbol' => 'ETHUSDT',
                'holdSide' => 'long',
                'posMode' => 'one_way_mode',
                'total' => '1.5',
                'available' => '1.5',
                'openPriceAvg' => '2500',
            ],
        ],
    ]);

    $result = $mapper->resolveQueryPositionsResponse($response);

    expect($result)->toHaveKey('ETHUSDT:BOTH');
    expect($result)->not->toHaveKey('ETHUSDT:LONG');
    expect($result['ETHUSDT:BOTH']['positionSide'])->toBe('BOTH');
    expect($result['ETHUSDT:BOTH']['positionAmt'])->toBe(1.5);
});

test('positions response in ONE-WAY SHORT keys by symbol:BOTH with negative positionAmt', function () {
    $mapper = new BitgetApiDataMapper;

    $response = bitgetMockResponse([
        'code' => '00000',
        'data' => [
            [
                'symbol' => 'ETHUSDT',
                'holdSide' => 'short',
                'posMode' => 'one_way_mode',
                'total' => '2.0',
                'available' => '2.0',
                'openPriceAvg' => '2400',
            ],
        ],
    ]);

    $result = $mapper->resolveQueryPositionsResponse($response);

    expect($result)->toHaveKey('ETHUSDT:BOTH');
    expect($result['ETHUSDT:BOTH']['positionSide'])->toBe('BOTH');
    expect($result['ETHUSDT:BOTH']['positionAmt'])->toBe(-2.0);
});
