<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Kucoin\KucoinApiDataMapper;

/**
 * Creates a test exchange symbol with unique identifier for KuCoin.
 */
function createKucoinTestExchangeSymbol(string $testId): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'kucoin',
        'name' => 'KuCoin',
    ]);

    $symbol = Symbol::factory()->create([
        'token' => 'XBT',
    ]);

    return ExchangeSymbol::factory()->create([
        'token' => 'XBT',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
}

/**
 * Creates a test position for KuCoin.
 */
function createKucoinTestPosition(string $testId): Position
{
    $exchangeSymbol = createKucoinTestExchangeSymbol($testId);

    $account = Account::factory()->create([
        'api_system_id' => $exchangeSymbol->api_system_id,
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'leverage' => 10,
        'total_limit_orders' => 4,
    ]);
}

/**
 * Creates a test order for KuCoin.
 */
function createKucoinTestOrder(string $testId, array $attributes = []): Order
{
    $position = createKucoinTestPosition($testId);

    return Order::create(array_merge([
        'position_id' => $position->id,
        'exchange_order_id' => '5cdfc138b21023a909e5ad55',
        'client_order_id' => 'test-client-order-123',
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '40000.00',
        'quantity' => '10',
        'position_side' => 'LONG',
    ], $attributes));
}

/**
 * Creates a mock Guzzle Response with JSON body.
 */
function createKucoinMockResponse(array $data, int $statusCode = 200): Response
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
    $order = createKucoinTestOrder('PLACE_LIMIT', ['type' => 'LIMIT']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.symbol'))->toBe('XBTUSDTM');
    expect($properties->get('options.side'))->toBe('buy');
    expect($properties->get('options.type'))->toBe('limit');
    expect($properties->get('options.leverage'))->toBe(10);
    expect($properties->get('options.timeInForce'))->toBe('GTC');
    expect($properties->get('options.price'))->not->toBeNull();
});

test('preparePlaceOrderProperties sets correct properties for MARKET order', function () {
    $order = createKucoinTestOrder('PLACE_MARKET', ['type' => 'MARKET']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.type'))->toBe('market');
    expect($properties->get('options.timeInForce'))->toBeNull();
    expect($properties->get('options.price'))->toBeNull();
});

test('preparePlaceOrderProperties sets stop properties for STOP-MARKET order closing LONG', function () {
    // STOP-MARKET to close LONG uses SELL side, triggers when price falls (stop=down)
    $order = createKucoinTestOrder('PLACE_STOP_LONG', ['type' => 'STOP-MARKET', 'side' => 'SELL']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.type'))->toBe('market');
    expect($properties->get('options.stop'))->toBe('down');
    expect($properties->get('options.stopPriceType'))->toBe('MP');
    expect($properties->get('options.stopPrice'))->not->toBeNull();
});

test('preparePlaceOrderProperties sets stop properties for STOP-MARKET order closing SHORT', function () {
    // STOP-MARKET to close SHORT uses BUY side, triggers when price rises (stop=up)
    $order = createKucoinTestOrder('PLACE_STOP_SHORT', ['type' => 'STOP-MARKET', 'side' => 'BUY']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.type'))->toBe('market');
    expect($properties->get('options.stop'))->toBe('up');
    expect($properties->get('options.stopPriceType'))->toBe('MP');
    expect($properties->get('options.stopPrice'))->not->toBeNull();
});

test('preparePlaceOrderProperties sets stop properties for TAKE-PROFIT order closing LONG', function () {
    // TAKE-PROFIT to close LONG uses SELL side, triggers when price rises (stop=up)
    $order = createKucoinTestOrder('PLACE_TP_LONG', ['type' => 'TAKE-PROFIT', 'side' => 'SELL']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.type'))->toBe('market');
    expect($properties->get('options.stop'))->toBe('up');
    expect($properties->get('options.stopPriceType'))->toBe('MP');
});

test('preparePlaceOrderProperties sets stop properties for TAKE-PROFIT order closing SHORT', function () {
    // TAKE-PROFIT to close SHORT uses BUY side, triggers when price falls (stop=down)
    $order = createKucoinTestOrder('PLACE_TP_SHORT', ['type' => 'TAKE-PROFIT', 'side' => 'BUY']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.type'))->toBe('market');
    expect($properties->get('options.stop'))->toBe('down');
    expect($properties->get('options.stopPriceType'))->toBe('MP');
});

test('resolvePlaceOrderResponse parses KuCoin response correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'orderId' => '5bd6e9286d99522a52e458de',
            'clientOid' => 'my-client-order-id',
        ],
    ]);

    $result = $mapper->resolvePlaceOrderResponse($response);

    expect($result['orderId'])->toBe('5bd6e9286d99522a52e458de');
    expect($result['clientOrderId'])->toBe('my-client-order-id');
    expect($result['status'])->toBe('NEW');
    expect($result['_raw'])->toBeArray();
});

// =============================================================================
// MapsOrderQuery Tests
// =============================================================================

test('prepareOrderQueryProperties sets correct properties', function () {
    $order = createKucoinTestOrder('QUERY_ORDER');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareOrderQueryProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.orderId'))->toBe('5cdfc138b21023a909e5ad55');
});

test('resolveOrderQueryResponse parses filled order correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'id' => '5cdfc138b21023a909e5ad55',
            'symbol' => 'XBTUSDTM',
            'type' => 'limit',
            'side' => 'buy',
            'price' => '40000',
            'size' => 20,
            'value' => '56.568',
            'dealValue' => '50.0',
            'dealSize' => 20,
            'filledSize' => 20,
            'filledValue' => '50.0',
            'leverage' => '10',
            'isActive' => false,
            'status' => 'done',
            'reduceOnly' => false,
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['order_id'])->toBe('5cdfc138b21023a909e5ad55');
    expect($result['symbol']['base'])->toBe('XBT');
    expect($result['symbol']['quote'])->toBe('USDT');
    expect($result['status'])->toBe('FILLED');
    expect($result['original_quantity'])->toBe('20');
    expect($result['executed_quantity'])->toBe('20');
    expect($result['type'])->toBe('limit');
    expect($result['_orderType'])->toBe('LIMIT');
    expect($result['side'])->toBe('BUY');
});

test('resolveOrderQueryResponse parses new order correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'id' => '9876543210',
            'symbol' => 'ETHUSDTM',
            'type' => 'limit',
            'side' => 'sell',
            'price' => '1800',
            'size' => 5,
            'filledSize' => 0,
            'isActive' => true,
            'status' => 'open',
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['status'])->toBe('NEW');
    expect($result['original_quantity'])->toBe('5');
    expect($result['executed_quantity'])->toBe('0');
    expect($result['_price'])->toBe('1800');
});

test('resolveOrderQueryResponse parses partially filled order correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'id' => '1111111111',
            'symbol' => 'XBTUSDTM',
            'type' => 'limit',
            'side' => 'buy',
            'price' => '40000',
            'size' => 20,
            'filledSize' => 10,
            'isActive' => true,
            'status' => 'match',
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['status'])->toBe('PARTIALLY_FILLED');
    expect($result['executed_quantity'])->toBe('10');
});

test('resolveOrderQueryResponse handles not found order', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['order_id'])->toBeNull();
    expect($result['status'])->toBe('NOT_FOUND');
});

// =============================================================================
// MapsOrderCancel Tests
// =============================================================================

test('prepareOrderCancelProperties sets correct properties', function () {
    $order = createKucoinTestOrder('CANCEL_ORDER');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareOrderCancelProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.orderId'))->toBe('5cdfc138b21023a909e5ad55');
});

test('resolveOrderCancelResponse parses response correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'cancelledOrderIds' => ['5bd6e9286d99522a52e458de'],
        ],
    ]);

    $result = $mapper->resolveOrderCancelResponse($response);

    expect($result['order_id'])->toBe('5bd6e9286d99522a52e458de');
    expect($result['status'])->toBe('CANCELLED');
});

test('resolveOrderCancelResponse handles empty cancelled list', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'cancelledOrderIds' => [],
        ],
    ]);

    $result = $mapper->resolveOrderCancelResponse($response);

    expect($result['order_id'])->toBeNull();
    expect($result['status'])->toBe('NOT_FOUND');
});

// =============================================================================
// MapsCancelOrders Tests
// =============================================================================

test('prepareCancelOrdersProperties sets correct properties', function () {
    $position = createKucoinTestPosition('CANCEL_ALL');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareCancelOrdersProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('XBTUSDTM');
});

test('resolveCancelOrdersResponse parses cancelled order ids', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'cancelledOrderIds' => [
                '5bd6e9286d99522a52e458de',
                '5bd6e9286d99522a52e458df',
                '5bd6e9286d99522a52e458e0',
            ],
        ],
    ]);

    $result = $mapper->resolveCancelOrdersResponse($response);

    expect($result['cancelledOrderIds'])->toHaveCount(3);
    expect($result['cancelledOrderIds'][0])->toBe('5bd6e9286d99522a52e458de');
});

// =============================================================================
// MapsAccountQueryTrades Tests
// =============================================================================

test('prepareQueryTokenTradesProperties sets correct properties', function () {
    $position = createKucoinTestPosition('QUERY_TRADES');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('XBTUSDTM');
});

test('prepareQueryTokenTradesProperties includes lastId when provided', function () {
    $position = createKucoinTestPosition('QUERY_TRADES_LAST');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position, '123456789');

    expect($properties->get('options.lastId'))->toBe('123456789');
});

test('resolveQueryTradeResponse parses fills correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'currentPage' => 1,
            'pageSize' => 50,
            'totalNum' => 2,
            'totalPage' => 1,
            'items' => [
                [
                    'symbol' => 'XBTUSDTM',
                    'tradeId' => '5ce24c16b210233c36ee321d',
                    'orderId' => '5ce24c16b210233c36ee321c',
                    'side' => 'buy',
                    'liquidity' => 'taker',
                    'price' => '8302',
                    'size' => 10,
                    'value' => '0.001205',
                    'feeRate' => '0.0005',
                    'fee' => '0.0006025',
                    'feeCurrency' => 'USDT',
                    'tradeTime' => 1558334496000000000,
                ],
                [
                    'symbol' => 'XBTUSDTM',
                    'tradeId' => '5ce24c16b210233c36ee321e',
                    'orderId' => '5ce24c16b210233c36ee321c',
                    'side' => 'buy',
                    'liquidity' => 'maker',
                    'price' => '8300',
                    'size' => 5,
                    'tradeTime' => 1558334497000000000,
                ],
            ],
        ],
    ]);

    $result = $mapper->resolveQueryTradeResponse($response);

    expect($result)->toHaveCount(2);
    expect($result[0]['tradeId'])->toBe('5ce24c16b210233c36ee321d');
    expect($result[0]['price'])->toBe('8302');
    expect($result[0]['size'])->toBe(10);
    expect($result[1]['tradeId'])->toBe('5ce24c16b210233c36ee321e');
});

// =============================================================================
// MapsMarkPriceQuery Tests
// =============================================================================

test('prepareQueryMarkPriceProperties sets correct properties', function () {
    $exchangeSymbol = createKucoinTestExchangeSymbol('MARK_PRICE');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareQueryMarkPriceProperties($exchangeSymbol);

    expect($properties->get('relatable'))->toBe($exchangeSymbol);
    expect($properties->get('options.symbol'))->toBe('XBTUSDTM');
});

test('resolveQueryMarkPriceResponse returns mark price value', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'symbol' => 'XBTUSDTM',
            'granularity' => 1000,
            'timePoint' => 1557894819000,
            'value' => 8287.86,
            'indexPrice' => 8287.86,
        ],
    ]);

    $result = $mapper->resolveQueryMarkPriceResponse($response);

    expect($result)->toBe('8287.86');
});

test('resolveQueryMarkPriceResponse returns null when value missing', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'symbol' => 'XBTUSDTM',
        ],
    ]);

    $result = $mapper->resolveQueryMarkPriceResponse($response);

    expect($result)->toBeNull();
});

// =============================================================================
// MapsLeverageBracketsQuery Tests
// =============================================================================

test('prepareQueryLeverageBracketsDataProperties sets correct properties', function () {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareQueryLeverageBracketsDataProperties($apiSystem);

    expect($properties->get('relatable'))->toBe($apiSystem);
});

test('resolveLeverageBracketsDataResponse parses risk limit levels', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            [
                'symbol' => 'XBTUSDTM',
                'level' => 1,
                'maxRiskLimit' => 200000,
                'minRiskLimit' => 0,
                'maxLeverage' => 100,
                'initialMargin' => 0.01,
                'maintainMargin' => 0.005,
            ],
            [
                'symbol' => 'XBTUSDTM',
                'level' => 2,
                'maxRiskLimit' => 500000,
                'minRiskLimit' => 200000,
                'maxLeverage' => 50,
                'initialMargin' => 0.02,
                'maintainMargin' => 0.01,
            ],
            [
                'symbol' => 'ETHUSDTM',
                'level' => 1,
                'maxRiskLimit' => 100000,
                'minRiskLimit' => 0,
                'maxLeverage' => 75,
                'initialMargin' => 0.0133,
                'maintainMargin' => 0.0067,
            ],
        ],
    ]);

    $result = $mapper->resolveLeverageBracketsDataResponse($response);

    expect($result)->toHaveCount(2); // Grouped by symbol
    expect($result['XBTUSDTM']['maxLeverage'])->toEqual(100);
    expect($result['XBTUSDTM']['levels'])->toHaveCount(2);
    expect($result['XBTUSDTM']['levels'][0]['level'])->toBe(1);
    expect($result['XBTUSDTM']['levels'][1]['level'])->toBe(2);
    expect($result['ETHUSDTM']['maxLeverage'])->toEqual(75);
    expect($result['ETHUSDTM']['levels'])->toHaveCount(1);
});

// =============================================================================
// MapsTokenLeverageRatios Tests
// =============================================================================

test('prepareUpdateLeverageRatioProperties sets correct properties', function () {
    $position = createKucoinTestPosition('SET_LEVERAGE');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareUpdateLeverageRatioProperties($position, 20);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('XBTUSDTM');
    expect($properties->get('options.leverage'))->toBe('20');
});

test('resolveUpdateLeverageRatioResponse parses success response', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => true,
    ]);

    $result = $mapper->resolveUpdateLeverageRatioResponse($response);

    expect($result['success'])->toBeTrue();
    expect($result['_raw'])->toBeArray();
});

test('resolveUpdateLeverageRatioResponse handles failure response', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => false,
    ]);

    $result = $mapper->resolveUpdateLeverageRatioResponse($response);

    expect($result['success'])->toBeFalse();
});

// =============================================================================
// MapsSymbolMarginType Tests
// =============================================================================

test('prepareUpdateMarginTypeProperties sets correct properties', function () {
    $position = createKucoinTestPosition('SET_MARGIN');
    $mapper = new KucoinApiDataMapper;

    $properties = $mapper->prepareUpdateMarginTypeProperties($position);

    // Account margin_mode is 'crossed', but KuCoin API uses 'CROSS' format (not 'CROSSED')
    $accountMarginMode = mb_strtoupper($position->account->margin_mode);
    $expectedMarginMode = $accountMarginMode === 'CROSSED' ? 'CROSS' : $accountMarginMode;

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('XBTUSDTM');
    expect($properties->get('options.marginMode'))->toBe($expectedMarginMode);
});

test('resolveUpdateMarginTypeResponse parses success response', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => true,
    ]);

    $result = $mapper->resolveUpdateMarginTypeResponse($response);

    expect($result['success'])->toBeTrue();
    expect($result['_raw'])->toBeArray();
});

// =============================================================================
// Canonical Order Type Tests
// =============================================================================

test('canonicalOrderType maps KuCoin order types correctly', function () {
    $mapper = new KucoinApiDataMapper;

    $testCases = [
        [['type' => 'market'], 'MARKET'],
        [['type' => 'limit'], 'LIMIT'],
        [['type' => 'limit', 'stop' => 'down'], 'STOP_MARKET'],
        [['type' => 'limit', 'stopPrice' => 40000], 'STOP_MARKET'],
        [['type' => 'market', 'stop' => 'up'], 'STOP_MARKET'],
        [['type' => 'unknown'], 'UNKNOWN'],
        [[], 'UNKNOWN'],
    ];

    foreach ($testCases as [$order, $expected]) {
        $result = $mapper->canonicalOrderType($order);
        expect($result)->toBe($expected, 'Failed for: '.json_encode($order));
    }
});

// =============================================================================
// Symbol Parsing and Formatting Tests
// =============================================================================

test('identifyBaseAndQuote parses KuCoin symbols correctly', function () {
    $mapper = new KucoinApiDataMapper;

    expect($mapper->identifyBaseAndQuote('XBTUSDTM'))->toBe(['base' => 'XBT', 'quote' => 'USDT']);
    expect($mapper->identifyBaseAndQuote('ETHUSDTM'))->toBe(['base' => 'ETH', 'quote' => 'USDT']);
    expect($mapper->identifyBaseAndQuote('XBTUSDM'))->toBe(['base' => 'XBT', 'quote' => 'USD']);
    expect($mapper->identifyBaseAndQuote('SOLUSDCM'))->toBe(['base' => 'SOL', 'quote' => 'USDC']);
});

test('baseWithQuote formats KuCoin perpetual symbols correctly', function () {
    $mapper = new KucoinApiDataMapper;

    expect($mapper->baseWithQuote('XBT', 'USDT'))->toBe('XBTUSDTM');
    expect($mapper->baseWithQuote('ETH', 'USDT'))->toBe('ETHUSDTM');
    expect($mapper->baseWithQuote('BTC', 'USDT'))->toBe('XBTUSDTM'); // BTC converts to XBT
    expect($mapper->baseWithQuote('SOL', 'USD'))->toBe('SOLUSDM');
});

test('sideType converts canonical sides to KuCoin format', function () {
    $mapper = new KucoinApiDataMapper;

    expect($mapper->sideType('BUY'))->toBe('buy');
    expect($mapper->sideType('SELL'))->toBe('sell');
});

test('directionType converts canonical directions to KuCoin format', function () {
    $mapper = new KucoinApiDataMapper;

    expect($mapper->directionType('LONG'))->toBe('long');
    expect($mapper->directionType('SHORT'))->toBe('short');
});

test('long returns lowercase long', function () {
    $mapper = new KucoinApiDataMapper;

    expect($mapper->long())->toBe('long');
});

test('short returns lowercase short', function () {
    $mapper = new KucoinApiDataMapper;

    expect($mapper->short())->toBe('short');
});

// =============================================================================
// Edge Cases and Error Handling
// =============================================================================

test('resolvers handle empty data gracefully', function () {
    $mapper = new KucoinApiDataMapper;

    $emptyResponse = createKucoinMockResponse([
        'code' => '200000',
        'data' => [],
    ]);

    expect($mapper->resolveCancelOrdersResponse($emptyResponse))->toBe([]);
    expect($mapper->resolveQueryTradeResponse($emptyResponse))->toBe([]);
    expect($mapper->resolveLeverageBracketsDataResponse($emptyResponse))->toBe([]);
});

test('resolvers handle missing data key gracefully', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
    ]);

    expect($mapper->resolvePlaceOrderResponse($response))->toHaveKeys(['orderId', 'clientOrderId', 'status', '_raw']);
    expect($mapper->resolveCancelOrdersResponse($response))->toBe([]);
    expect($mapper->resolveQueryTradeResponse($response))->toBe([]);
});

test('resolvers handle missing items key in paginated response', function () {
    $mapper = new KucoinApiDataMapper;

    $response = createKucoinMockResponse([
        'code' => '200000',
        'data' => [
            'currentPage' => 1,
            'pageSize' => 50,
            'totalNum' => 0,
            'totalPage' => 0,
        ],
    ]);

    expect($mapper->resolveQueryTradeResponse($response))->toBe([]);
});

test('sideType throws exception for invalid side', function () {
    $mapper = new KucoinApiDataMapper;

    expect(function () use ($mapper) {
        $mapper->sideType('INVALID');
    })->toThrow(InvalidArgumentException::class);
});

test('directionType throws exception for invalid direction', function () {
    $mapper = new KucoinApiDataMapper;

    expect(function () use ($mapper) {
        $mapper->directionType('INVALID');
    })->toThrow(InvalidArgumentException::class);
});

test('identifyBaseAndQuote throws exception for invalid symbol format', function () {
    $mapper = new KucoinApiDataMapper;

    expect(function () use ($mapper) {
        $mapper->identifyBaseAndQuote('INVALIDFORMAT');
    })->toThrow(InvalidArgumentException::class);
});
