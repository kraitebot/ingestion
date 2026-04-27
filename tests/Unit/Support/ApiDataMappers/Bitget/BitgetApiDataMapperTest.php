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

/**
 * Creates a test exchange symbol with unique identifier.
 */
function createBitgetTestExchangeSymbol(string $testId): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'BitGet',
    ]);

    $symbol = Symbol::factory()->create([
        'token' => 'BTC',
    ]);

    return ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
}

/**
 * Creates a test position for BitGet.
 */
function createBitgetTestPosition(string $testId): Position
{
    $exchangeSymbol = createBitgetTestExchangeSymbol($testId);

    $account = Account::factory()->create([
        'api_system_id' => $exchangeSymbol->api_system_id,
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'total_limit_orders' => 4,
    ]);
}

/**
 * Creates a test order for BitGet.
 */
function createBitgetTestOrder(string $testId, array $attributes = []): Order
{
    $position = createBitgetTestPosition($testId);

    return Order::create(array_merge([
        'position_id' => $position->id,
        'exchange_order_id' => '1234567890',
        'client_order_id' => 'test-client-order-123',
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '40000.00',
        'quantity' => '0.001',
        'position_side' => 'LONG',
    ], $attributes));
}

/**
 * Creates a mock Guzzle Response with JSON body.
 */
function createBitgetMockResponse(array $data, int $statusCode = 200): Response
{
    return new Response(
        $statusCode,
        ['Content-Type' => 'application/json'],
        json_encode($data)
    );
}

// =============================================================================
// MapsPlaceOrder Tests
// =============================================================================

test('preparePlaceOrderProperties sets correct properties for LIMIT order', function () {
    $order = createBitgetTestOrder('PLACE_LIMIT', ['type' => 'LIMIT']);
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.marginMode'))->toBe('crossed');
    expect($properties->get('options.marginCoin'))->toBe('USDT');
    expect($properties->get('options.side'))->toBe('buy');
    expect($properties->get('options.orderType'))->toBe('limit');
    expect($properties->get('options.force'))->toBe('gtc');
    expect($properties->get('options.price'))->not->toBeNull();
});

test('preparePlaceOrderProperties sets correct properties for MARKET order', function () {
    $order = createBitgetTestOrder('PLACE_MARKET', ['type' => 'MARKET']);
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.orderType'))->toBe('market');
    expect($properties->get('options.force'))->toBeNull();
    expect($properties->get('options.price'))->toBeNull();
});

test('resolvePlaceOrderResponse parses BitGet response correctly', function () {
    $mapper = new BitgetApiDataMapper;

    // BitGet place order response per documentation
    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'requestTime' => 1695806875837,
        'data' => [
            'orderId' => '121211212122',
            'clientOid' => 'my-client-order-id',
        ],
    ]);

    $result = $mapper->resolvePlaceOrderResponse($response);

    expect($result['orderId'])->toBe('121211212122');
    expect($result['clientOid'])->toBe('my-client-order-id');
    expect($result['_price'])->toBe('0');
    expect($result['_orderType'])->toBe('UNKNOWN');
});

// =============================================================================
// MapsOrderQuery Tests
// =============================================================================

test('prepareOrderQueryProperties sets correct properties', function () {
    $order = createBitgetTestOrder('QUERY_ORDER');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareOrderQueryProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.orderId'))->toBe('1234567890');
});

test('resolveOrderQueryResponse parses filled order correctly', function () {
    $mapper = new BitgetApiDataMapper;

    // BitGet order detail response per documentation
    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            'symbol' => 'BTCUSDT',
            'size' => '0.001',
            'orderId' => '1234567890',
            'clientOid' => 'test-client-123',
            'filledQty' => '0.001',
            'priceAvg' => '40100',
            'fee' => '0.02',
            'price' => '40000',
            'state' => 'filled',
            'side' => 'buy',
            'orderType' => 'limit',
            'leverage' => '10',
            'marginMode' => 'crossed',
            'posSide' => 'long',
            'tradeSide' => 'open',
            'cTime' => '1627116936176',
            'uTime' => '1627116936180',
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['order_id'])->toBe('1234567890');
    expect($result['symbol']['base'])->toBe('BTC');
    expect($result['symbol']['quote'])->toBe('USDT');
    expect($result['status'])->toBe('FILLED');
    expect($result['price'])->toBe('40100'); // priceAvg since filled
    expect($result['quantity'])->toBe('0.001'); // filledQty since > 0
    expect($result['type'])->toBe('limit');
    expect($result['_orderType'])->toBe('LIMIT');
    expect($result['side'])->toBe('buy');
    expect($result['_raw'])->toBeArray();
});

test('resolveOrderQueryResponse parses new order correctly', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            'symbol' => 'ETHUSDT',
            'size' => '0.05',
            'orderId' => '9876543210',
            'clientOid' => 'test-new-order',
            'filledQty' => '0',
            'priceAvg' => '0',
            'price' => '1800',
            'state' => 'new',
            'side' => 'sell',
            'orderType' => 'limit',
            'posSide' => 'short',
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['status'])->toBe('NEW');
    expect($result['price'])->toBe('1800'); // price since not filled
    expect($result['quantity'])->toBe('0.05'); // size since filledQty is 0
    expect($result['_price'])->toBe('1800');
});

test('resolveOrderQueryResponse normalizes various states correctly', function () {
    $mapper = new BitgetApiDataMapper;

    $testCases = [
        'new' => 'NEW',
        'live' => 'NEW',
        'filled' => 'FILLED',
        'full-fill' => 'FILLED',
        'partially_filled' => 'PARTIALLY_FILLED',
        'partial-fill' => 'PARTIALLY_FILLED',
        'cancelled' => 'CANCELLED',
        'canceled' => 'CANCELLED',
    ];

    foreach ($testCases as $bitgetState => $expectedStatus) {
        $response = createBitgetMockResponse([
            'code' => '00000',
            'data' => [
                'symbol' => 'BTCUSDT',
                'orderId' => '123',
                'state' => $bitgetState,
                'size' => '0.001',
                'price' => '40000',
                'orderType' => 'limit',
            ],
        ]);

        $result = $mapper->resolveOrderQueryResponse($response);
        expect($result['status'])->toBe($expectedStatus, "Failed for state: {$bitgetState}");
    }
});

// =============================================================================
// MapsOrderCancel Tests
// =============================================================================

test('prepareOrderCancelProperties sets correct properties', function () {
    $order = createBitgetTestOrder('CANCEL_ORDER');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareOrderCancelProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.marginCoin'))->toBe('USDT');
    expect($properties->get('options.orderId'))->toBe('1234567890');
});

test('resolveOrderCancelResponse parses response correctly', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            'orderId' => '1234567890',
            'clientOid' => 'cancelled-order-123',
        ],
    ]);

    $result = $mapper->resolveOrderCancelResponse($response);

    expect($result['order_id'])->toBe('1234567890');
    expect($result['clientOid'])->toBe('cancelled-order-123');
    expect($result['status'])->toBe('CANCELLED');
});

// =============================================================================
// MapsOrderModify Tests
// =============================================================================

test('prepareOrderModifyProperties sets correct properties', function () {
    $order = createBitgetTestOrder('MODIFY_ORDER');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareOrderModifyProperties($order, '0.002', '41000');

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.marginCoin'))->toBe('USDT');
    expect($properties->get('options.orderId'))->toBe('1234567890');
    expect($properties->get('options.newSize'))->toBe('0.002');
    expect($properties->get('options.newPrice'))->toBe('41000');
    expect($properties->get('options.newClientOid'))->not->toBeNull();
});

test('resolveOrderModifyResponse parses response correctly', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            'orderId' => '1234567890',
            'clientOid' => 'modified-order-456',
        ],
    ]);

    $result = $mapper->resolveOrderModifyResponse($response);

    expect($result['order_id'])->toBe('1234567890');
    expect($result['clientOid'])->toBe('modified-order-456');
});

// =============================================================================
// MapsCancelOrders Tests
// =============================================================================

test('prepareCancelOrdersProperties sets correct properties', function () {
    $position = createBitgetTestPosition('CANCEL_ALL');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareCancelOrdersProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.marginCoin'))->toBe('USDT');
});

test('resolveCancelOrdersResponse parses response with success and failure lists', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            'successList' => [
                ['orderId' => '123', 'clientOid' => 'xxx'],
                ['orderId' => '456', 'clientOid' => 'yyy'],
            ],
            'failureList' => [
                ['orderId' => '789', 'clientOid' => 'zzz', 'errorMsg' => 'Order not found'],
            ],
        ],
    ]);

    $result = $mapper->resolveCancelOrdersResponse($response);

    expect($result['successList'])->toHaveCount(2);
    expect($result['failureList'])->toHaveCount(1);
    expect($result['successList'][0]['orderId'])->toBe('123');
    expect($result['failureList'][0]['errorMsg'])->toBe('Order not found');
});

// =============================================================================
// MapsAccountQueryTrades Tests
// =============================================================================

test('prepareQueryTokenTradesProperties sets correct properties', function () {
    $position = createBitgetTestPosition('QUERY_TRADES');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
});

test('prepareQueryTokenTradesProperties includes orderId when provided', function () {
    $position = createBitgetTestPosition('QUERY_TRADES_ORDER');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position, '1234567890');

    expect($properties->get('options.orderId'))->toBe('1234567890');
});

test('resolveQueryTradeResponse parses fillList correctly', function () {
    $mapper = new BitgetApiDataMapper;

    // BitGet order fills response per documentation
    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            'fillList' => [
                [
                    'tradeId' => '123456',
                    'symbol' => 'BTCUSDT',
                    'orderId' => '789',
                    'price' => '40000',
                    'baseVolume' => '0.001',
                    'feeDetail' => [
                        ['feeCoin' => 'USDT', 'totalFee' => '0.02'],
                    ],
                    'side' => 'buy',
                    'quoteVolume' => '40',
                    'profit' => '0',
                    'tradeSide' => 'open',
                    'cTime' => '1627116936176',
                ],
                [
                    'tradeId' => '123457',
                    'symbol' => 'BTCUSDT',
                    'orderId' => '790',
                    'price' => '40100',
                    'baseVolume' => '0.002',
                    'side' => 'sell',
                    'tradeSide' => 'close',
                    'cTime' => '1627116946176',
                ],
            ],
            'endId' => '123457',
        ],
        'msg' => 'success',
    ]);

    $result = $mapper->resolveQueryTradeResponse($response);

    // Mapper now reverses Bitget's newest-first response to oldest-first
    // (Binance convention). Input was [123456 (open), 123457 (close)] in
    // input order; reversed output is [123457, 123456].
    expect($result)->toHaveCount(2);
    expect($result[0]['tradeId'])->toBe('123457');
    expect($result[1]['tradeId'])->toBe('123456');
    expect($result[1]['price'])->toBe('40000');
    expect($result[1]['baseVolume'])->toBe('0.001');
});

// =============================================================================
// MapsMarkPriceQuery Tests
// =============================================================================

test('prepareQueryMarkPriceProperties sets correct properties', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('MARK_PRICE');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryMarkPriceProperties($exchangeSymbol);

    expect($properties->get('relatable'))->toBe($exchangeSymbol);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
});

test('resolveQueryMarkPriceResponse returns mark price', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            [
                'symbol' => 'BTCUSDT',
                'markPrice' => '40500.5',
                'indexPrice' => '40495.2',
                'price' => '40510.0',
            ],
        ],
    ]);

    $result = $mapper->resolveQueryMarkPriceResponse($response);

    expect($result)->toBe('40500.5');
});

test('resolveQueryMarkPriceResponse returns null when mark price missing', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            [
                'symbol' => 'BTCUSDT',
            ],
        ],
    ]);

    $result = $mapper->resolveQueryMarkPriceResponse($response);

    expect($result)->toBeNull();
});

// =============================================================================
// MapsLeverageBracketsQuery Tests
// =============================================================================

test('prepareQueryLeverageBracketsDataProperties sets correct properties', function () {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryLeverageBracketsDataProperties($apiSystem);

    expect($properties->get('relatable'))->toBe($apiSystem);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
});

test('prepareQueryLeverageBracketsDataProperties accepts optional symbol', function () {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryLeverageBracketsDataProperties($apiSystem, 'BTCUSDT');

    expect($properties->get('relatable'))->toBe($apiSystem);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
});

test('resolveLeverageBracketsDataResponse parses position tier data', function () {
    $mapper = new BitgetApiDataMapper;

    // BitGet V2 position tier response format
    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            [
                'symbol' => 'BTCUSDT',
                'level' => '1',
                'startUnit' => '0',
                'endUnit' => '50000',
                'leverage' => '125',
                'keepMarginRate' => '0.004',
            ],
            [
                'symbol' => 'BTCUSDT',
                'level' => '2',
                'startUnit' => '50000',
                'endUnit' => '200000',
                'leverage' => '100',
                'keepMarginRate' => '0.005',
            ],
            [
                'symbol' => 'ETHUSDT',
                'level' => '1',
                'startUnit' => '0',
                'endUnit' => '25000',
                'leverage' => '100',
                'keepMarginRate' => '0.005',
            ],
        ],
    ]);

    $result = $mapper->resolveLeverageBracketsDataResponse($response);

    // Should be grouped by symbol
    expect($result)->toHaveCount(2);
    expect($result[0]['symbol'])->toBe('BTCUSDT');
    expect($result[0]['brackets'])->toHaveCount(2);
    expect($result[0]['brackets'][0]['bracket'])->toBe(1);
    expect($result[0]['brackets'][0]['initialLeverage'])->toBe(125);
    expect($result[0]['brackets'][0]['notionalCap'])->toBe(50000.0);
    expect($result[0]['brackets'][0]['notionalFloor'])->toBe(0.0);
    expect($result[0]['brackets'][0]['maintMarginRatio'])->toBe(0.004);
    expect($result[1]['symbol'])->toBe('ETHUSDT');
    expect($result[1]['brackets'])->toHaveCount(1);
});

// =============================================================================
// MapsTokenLeverageRatios Tests
// =============================================================================

test('prepareTokenLeverageRatiosProperties sets correct properties', function () {
    $position = createBitgetTestPosition('SET_LEVERAGE');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareTokenLeverageRatiosProperties($position, '20');

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.marginCoin'))->toBe('USDT');
    expect($properties->get('options.leverage'))->toBe('20');
    expect($properties->get('options.holdSide'))->toBe('long');
})->todo('MapsTokenLeverageRatios trait not yet implemented');

test('resolveTokenLeverageRatiosResponse parses leverage confirmation', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            'symbol' => 'BTCUSDT',
            'marginCoin' => 'USDT',
            'longLeverage' => '20',
            'shortLeverage' => '20',
        ],
    ]);

    $result = $mapper->resolveTokenLeverageRatiosResponse($response);

    expect($result['symbol'])->toBe('BTCUSDT');
    expect($result['longLeverage'])->toBe('20');
    expect($result['shortLeverage'])->toBe('20');
})->todo('MapsTokenLeverageRatios trait not yet implemented');

// =============================================================================
// MapsSymbolMarginType Tests
// =============================================================================

test('prepareSymbolMarginTypeProperties sets correct properties', function () {
    $position = createBitgetTestPosition('SET_MARGIN');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareSymbolMarginTypeProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.marginCoin'))->toBe('USDT');
    // Uses the account's margin_mode setting (default is 'isolated')
    expect($properties->get('options.marginMode'))->toBe($position->account->margin_mode);
})->todo('MapsSymbolMarginType trait not yet implemented');

test('resolveSymbolMarginTypeResponse parses margin mode confirmation', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            'symbol' => 'BTCUSDT',
            'marginCoin' => 'USDT',
            'marginMode' => 'crossed',
        ],
    ]);

    $result = $mapper->resolveSymbolMarginTypeResponse($response);

    expect($result['symbol'])->toBe('BTCUSDT');
    expect($result['marginMode'])->toBe('crossed');
})->todo('MapsSymbolMarginType trait not yet implemented');

// =============================================================================
// Canonical Order Type Tests
// =============================================================================

test('canonicalOrderType maps BitGet order types correctly', function () {
    $mapper = new BitgetApiDataMapper;

    $testCases = [
        [['orderType' => 'market'], 'MARKET'],
        [['orderType' => 'limit'], 'LIMIT'],
        [['orderType' => 'limit', 'triggerPrice' => '40000'], 'STOP_MARKET'],
        [['planType' => 'pos_loss'], 'STOP_MARKET'],
        [['planType' => 'loss_plan'], 'STOP_MARKET'],
        [['planType' => 'pos_profit'], 'TAKE_PROFIT'],
        [['planType' => 'profit_plan'], 'TAKE_PROFIT'],
        [['planType' => 'normal_plan'], 'STOP_MARKET'],
        [['planType' => 'track_plan'], 'STOP_MARKET'],
        [['orderType' => 'unknown_type'], 'UNKNOWN'],
    ];

    foreach ($testCases as [$order, $expected]) {
        $result = $mapper->canonicalOrderType($order);
        expect($result)->toBe($expected, 'Failed for: '.json_encode($order));
    }
});

// =============================================================================
// Edge Cases and Error Handling
// =============================================================================

test('resolvers handle empty data gracefully', function () {
    $mapper = new BitgetApiDataMapper;

    $emptyResponse = createBitgetMockResponse([
        'code' => '00000',
        'data' => [],
    ]);

    // These should not throw exceptions
    expect($mapper->resolveCancelOrdersResponse($emptyResponse))->toBe([]);
    expect($mapper->resolveQueryTradeResponse($emptyResponse))->toBe([]);
    expect($mapper->resolveLeverageBracketsDataResponse($emptyResponse))->toBe([]);
});

test('resolvers handle missing data key gracefully', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
    ]);

    expect($mapper->resolvePlaceOrderResponse($response))->toHaveKeys(['_price', '_orderType']);
    expect($mapper->resolveCancelOrdersResponse($response))->toBe([]);
    expect($mapper->resolveQueryTradeResponse($response))->toBe([]);
});

test('sideType converts canonical sides to BitGet format', function () {
    $mapper = new BitgetApiDataMapper;

    expect($mapper->sideType('BUY'))->toBe('buy');
    expect($mapper->sideType('buy'))->toBe('buy');
    expect($mapper->sideType('SELL'))->toBe('sell');
    expect($mapper->sideType('sell'))->toBe('sell');
});

test('directionType converts canonical directions to BitGet format', function () {
    $mapper = new BitgetApiDataMapper;

    expect($mapper->directionType('LONG'))->toBe('long');
    expect($mapper->directionType('long'))->toBe('long');
    expect($mapper->directionType('SHORT'))->toBe('short');
    expect($mapper->directionType('short'))->toBe('short');
});

test('identifyBaseAndQuote parses BitGet symbols correctly', function () {
    $mapper = new BitgetApiDataMapper;

    expect($mapper->identifyBaseAndQuote('BTCUSDT'))->toBe(['base' => 'BTC', 'quote' => 'USDT']);
    expect($mapper->identifyBaseAndQuote('ETHUSDC'))->toBe(['base' => 'ETH', 'quote' => 'USDC']);
    expect($mapper->identifyBaseAndQuote('SOLUSD'))->toBe(['base' => 'SOL', 'quote' => 'USD']);
});

// =============================================================================
// MapsKlinesQuery Tests
// =============================================================================

test('prepareQueryKlinesProperties sets correct properties', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('KLINES_QUERY');
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryKlinesProperties($exchangeSymbol, '5m', null, null, 100);

    expect($properties->get('relatable'))->toBe($exchangeSymbol);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.granularity'))->toBe('5m');
    expect($properties->get('options.productType'))->toBe('USDT-FUTURES');
    expect($properties->get('options.limit'))->toBe(100);
});

test('prepareQueryKlinesProperties normalizes lowercase hour timeframes to uppercase', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('KLINES_HOUR');
    $mapper = new BitgetApiDataMapper;

    // Bitget Futures API requires uppercase H for hours
    $testCases = [
        '1h' => '1H',
        '2h' => '2H',
        '4h' => '4H',
        '6h' => '6H',
        '12h' => '12H',
        '1H' => '1H', // Already uppercase should stay unchanged
        '4H' => '4H',
    ];

    foreach ($testCases as $input => $expected) {
        $properties = $mapper->prepareQueryKlinesProperties($exchangeSymbol, $input);
        expect($properties->get('options.granularity'))->toBe($expected, "Failed for input: {$input}");
    }
});

test('prepareQueryKlinesProperties normalizes lowercase day timeframes to uppercase', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('KLINES_DAY');
    $mapper = new BitgetApiDataMapper;

    $testCases = [
        '1d' => '1D',
        '3d' => '3D',
        '1D' => '1D',
    ];

    foreach ($testCases as $input => $expected) {
        $properties = $mapper->prepareQueryKlinesProperties($exchangeSymbol, $input);
        expect($properties->get('options.granularity'))->toBe($expected, "Failed for input: {$input}");
    }
});

test('prepareQueryKlinesProperties normalizes lowercase week timeframes to uppercase', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('KLINES_WEEK');
    $mapper = new BitgetApiDataMapper;

    $testCases = [
        '1w' => '1W',
        '1W' => '1W',
    ];

    foreach ($testCases as $input => $expected) {
        $properties = $mapper->prepareQueryKlinesProperties($exchangeSymbol, $input);
        expect($properties->get('options.granularity'))->toBe($expected, "Failed for input: {$input}");
    }
});

test('prepareQueryKlinesProperties keeps minute timeframes lowercase', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('KLINES_MINUTE');
    $mapper = new BitgetApiDataMapper;

    // Minutes should stay lowercase
    $testCases = ['1m', '3m', '5m', '15m', '30m'];

    foreach ($testCases as $timeframe) {
        $properties = $mapper->prepareQueryKlinesProperties($exchangeSymbol, $timeframe);
        expect($properties->get('options.granularity'))->toBe($timeframe, "Failed for: {$timeframe}");
    }
});

test('prepareQueryKlinesProperties includes optional time parameters', function () {
    $exchangeSymbol = createBitgetTestExchangeSymbol('KLINES_TIME');
    $mapper = new BitgetApiDataMapper;

    $startTime = 1704067200000;
    $endTime = 1704153600000;

    $properties = $mapper->prepareQueryKlinesProperties($exchangeSymbol, '5m', $startTime, $endTime, 500);

    expect($properties->get('options.startTime'))->toBe($startTime);
    expect($properties->get('options.endTime'))->toBe($endTime);
    expect($properties->get('options.limit'))->toBe(500);
});

test('resolveQueryKlinesResponse parses klines data correctly', function () {
    $mapper = new BitgetApiDataMapper;

    // Bitget klines response format: [timestamp, open, high, low, close, volume, quoteVolume]
    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            ['1704067200000', '42000.5', '42500.0', '41800.0', '42300.0', '1234.56', '52000000'],
            ['1704070800000', '42300.0', '42800.0', '42100.0', '42600.0', '987.65', '42000000'],
        ],
    ]);

    $result = $mapper->resolveQueryKlinesResponse($response);

    expect($result)->toHaveCount(2);

    expect($result[0]['timestamp'])->toBe(1704067200000);
    expect($result[0]['open'])->toBe('42000.5');
    expect($result[0]['high'])->toBe('42500.0');
    expect($result[0]['low'])->toBe('41800.0');
    expect($result[0]['close'])->toBe('42300.0');
    expect($result[0]['volume'])->toBe('1234.56');

    expect($result[1]['timestamp'])->toBe(1704070800000);
    expect($result[1]['open'])->toBe('42300.0');
});

test('resolveQueryKlinesResponse handles empty data', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
        'data' => [],
    ]);

    $result = $mapper->resolveQueryKlinesResponse($response);

    expect($result)->toBe([]);
});

test('resolveQueryKlinesResponse handles missing data key', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'msg' => 'success',
    ]);

    $result = $mapper->resolveQueryKlinesResponse($response);

    expect($result)->toBe([]);
});

test('resolveQueryKlinesResponse skips malformed candles', function () {
    $mapper = new BitgetApiDataMapper;

    $response = createBitgetMockResponse([
        'code' => '00000',
        'data' => [
            ['1704067200000', '42000.5', '42500.0', '41800.0', '42300.0', '1234.56'], // Valid
            ['1704070800000', '42300.0'], // Invalid - too few elements
            'invalid', // Invalid - not an array
            ['1704074400000', '42400.0', '42600.0', '42200.0', '42500.0', '567.89'], // Valid
        ],
    ]);

    $result = $mapper->resolveQueryKlinesResponse($response);

    expect($result)->toHaveCount(2);
    expect($result[0]['timestamp'])->toBe(1704067200000);
    expect($result[1]['timestamp'])->toBe(1704074400000);
});
