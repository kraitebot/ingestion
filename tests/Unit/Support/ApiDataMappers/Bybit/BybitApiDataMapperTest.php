<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper;

/**
 * Creates a test exchange symbol with unique identifier for Bybit.
 */
function createBybitTestExchangeSymbol(string $testId): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
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
 * Creates a test position for Bybit.
 */
function createBybitTestPosition(string $testId): Position
{
    $exchangeSymbol = createBybitTestExchangeSymbol($testId);

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
 * Creates a test order for Bybit.
 */
function createBybitTestOrder(string $testId, array $attributes = []): Order
{
    $position = createBybitTestPosition($testId);

    return Order::create(array_merge([
        'position_id' => $position->id,
        'exchange_order_id' => 'fd4300ae-7847-404e-b947-b46980a4d140',
        'client_order_id' => 'test-client-order-123',
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '40000.00',
        'quantity' => '0.10',
        'position_side' => 'LONG',
    ], $attributes));
}

/**
 * Creates a mock Guzzle Response with JSON body.
 */
function createBybitMockResponse(array $data, int $statusCode = 200): Response
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

test('preparePlaceOrderProperties sets correct properties for LIMIT order', function (): void {
    $order = createBybitTestOrder('PLACE_LIMIT', ['type' => 'LIMIT']);
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.side'))->toBe('Buy');
    expect($properties->get('options.orderType'))->toBe('Limit');
    expect($properties->get('options.category'))->toBe('linear');
    expect($properties->get('options.timeInForce'))->toBe('GTC');
    expect($properties->get('options.positionIdx'))->toBe(0);
});

test('preparePlaceOrderProperties sets correct properties for MARKET order', function (): void {
    $order = createBybitTestOrder('PLACE_MARKET', ['type' => 'MARKET']);
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.orderType'))->toBe('Market');
    expect($properties->get('options.price'))->toBeNull();
});

test('preparePlaceOrderProperties sets trigger properties for STOP-MARKET order', function (): void {
    $order = createBybitTestOrder('PLACE_STOP', ['type' => 'STOP-MARKET', 'side' => 'SELL']);
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.orderType'))->toBe('Market');
    expect($properties->get('options.triggerPrice'))->not->toBeNull();
    expect($properties->get('options.triggerDirection'))->toBe(2);
});

test('preparePlaceOrderProperties sets trigger properties for TAKE-PROFIT order', function (): void {
    $order = createBybitTestOrder('PLACE_TP', ['type' => 'TAKE-PROFIT', 'side' => 'SELL']);
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.orderType'))->toBe('Market');
    expect($properties->get('options.triggerPrice'))->not->toBeNull();
    expect($properties->get('options.triggerDirection'))->toBe(2);
});

test('resolvePlaceOrderResponse parses Bybit response correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'orderId' => '1321003749386327552',
            'orderLinkId' => 'test-orderLinkId',
        ],
    ]);

    $result = $mapper->resolvePlaceOrderResponse($response);

    expect($result['orderId'])->toBe('1321003749386327552');
    expect($result['clientOrderId'])->toBe('test-orderLinkId');
    expect($result['status'])->toBe('NEW');
});

// =============================================================================
// MapsOrderQuery Tests
// =============================================================================

test('prepareOrderQueryProperties sets correct properties', function (): void {
    $order = createBybitTestOrder('QUERY');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareOrderQueryProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.orderId'))->toBe('fd4300ae-7847-404e-b947-b46980a4d140');
    expect($properties->get('options.category'))->toBe('linear');
});

test('resolveOrderQueryResponse parses filled order correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'list' => [[
                'orderId' => 'fd4300ae-7847-404e-b947-b46980a4d140',
                'orderLinkId' => 'test-000005',
                'symbol' => 'BTCUSDT',
                'price' => '40000.00',
                'qty' => '0.10',
                'side' => 'Buy',
                'orderType' => 'Limit',
                'orderStatus' => 'Filled',
                'avgPrice' => '39998.50',
                'cumExecQty' => '0.10',
            ]],
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['order_id'])->toBe('fd4300ae-7847-404e-b947-b46980a4d140');
    expect($result['status'])->toBe('FILLED');
    expect($result['side'])->toBe('BUY');
    expect($result['executed_quantity'])->toBe('0.10');
});

test('resolveOrderQueryResponse parses new order correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'list' => [[
                'orderId' => 'fd4300ae-7847-404e-b947-b46980a4d140',
                'symbol' => 'BTCUSDT',
                'orderStatus' => 'New',
                'cumExecQty' => '0',
            ]],
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['status'])->toBe('NEW');
});

test('resolveOrderQueryResponse parses partially filled order correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'list' => [[
                'orderId' => 'fd4300ae-7847-404e-b947-b46980a4d140',
                'symbol' => 'BTCUSDT',
                'orderStatus' => 'PartiallyFilled',
                'cumExecQty' => '0.05',
            ]],
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['status'])->toBe('PARTIALLY_FILLED');
});

test('resolveOrderQueryResponse handles not found order', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'list' => [],
        ],
    ]);

    $result = $mapper->resolveOrderQueryResponse($response);

    expect($result['order_id'])->toBeNull();
    expect($result['status'])->toBe('NOT_FOUND');
});

// =============================================================================
// MapsOrderCancel Tests
// =============================================================================

test('prepareOrderCancelProperties sets correct properties', function (): void {
    $order = createBybitTestOrder('CANCEL');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareOrderCancelProperties($order);

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.orderId'))->toBe('fd4300ae-7847-404e-b947-b46980a4d140');
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
});

test('resolveOrderCancelResponse parses response correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'orderId' => 'c6f055d9-7f21-4079-913d-e6523a9cfffa',
            'orderLinkId' => 'linear-004',
        ],
    ]);

    $result = $mapper->resolveOrderCancelResponse($response);

    expect($result['orderId'])->toBe('c6f055d9-7f21-4079-913d-e6523a9cfffa');
    expect($result['clientOrderId'])->toBe('linear-004');
    expect($result['success'])->toBeTrue();
});

test('resolveOrderCancelResponse handles empty result', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [],
    ]);

    $result = $mapper->resolveOrderCancelResponse($response);

    expect($result['success'])->toBeFalse();
});

// =============================================================================
// MapsCancelOrders Tests
// =============================================================================

test('prepareCancelOrdersProperties sets correct properties', function (): void {
    $position = createBybitTestPosition('CANCEL_ALL');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareCancelOrdersProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
});

test('resolveCancelOrdersResponse parses cancelled order ids', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'list' => [
                ['orderId' => 'order-1', 'orderLinkId' => 'link-1'],
                ['orderId' => 'order-2', 'orderLinkId' => 'link-2'],
            ],
            'success' => '1',
        ],
    ]);

    $result = $mapper->resolveCancelOrdersResponse($response);

    expect($result['cancelledOrderIds'])->toHaveCount(2);
    expect($result['cancelledOrderIds'])->toContain('order-1', 'order-2');
    expect($result['success'])->toBeTrue();
});

// =============================================================================
// MapsOrderModify Tests
// =============================================================================

test('prepareOrderModifyProperties sets correct properties', function (): void {
    $order = createBybitTestOrder('MODIFY');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareOrderModifyProperties($order, '0.20', '41000.00');

    expect($properties->get('relatable'))->toBe($order);
    expect($properties->get('options.orderId'))->toBe('fd4300ae-7847-404e-b947-b46980a4d140');
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
});

test('resolveOrderModifyResponse parses amended order correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'orderId' => 'c6f055d9-7f21-4079-913d-e6523a9cfffa',
            'orderLinkId' => 'linear-004',
        ],
    ]);

    $result = $mapper->resolveOrderModifyResponse($response);

    expect($result['order_id'])->toBe('c6f055d9-7f21-4079-913d-e6523a9cfffa');
    expect($result['client_order_id'])->toBe('linear-004');
    expect($result['status'])->toBe('AMENDED');
});

test('resolveOrderModifyResponse handles failure response', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 10001,
        'retMsg' => 'Error',
        'result' => [],
    ]);

    $result = $mapper->resolveOrderModifyResponse($response);

    expect($result['status'])->toBe('FAILED');
});

// =============================================================================
// MapsAccountQueryTrades Tests
// =============================================================================

test('prepareQueryTokenTradesProperties sets correct properties', function (): void {
    $position = createBybitTestPosition('TRADES');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
    expect($properties->get('options.limit'))->toBe(50);
});

test('prepareQueryTokenTradesProperties includes cursor when provided', function (): void {
    $position = createBybitTestPosition('TRADES_CURSOR');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position, 'next-page-cursor');

    expect($properties->get('options.cursor'))->toBe('next-page-cursor');
});

test('resolveQueryTradeResponse parses executions correctly', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'category' => 'linear',
            'list' => [
                [
                    'execId' => '2e5a09ee-1234-5678',
                    'orderId' => '1666a13a-5678',
                    'symbol' => 'BTCUSDT',
                    'side' => 'Buy',
                    'execPrice' => '40000.00',
                    'execQty' => '0.10',
                    'execFee' => '0.24',
                    'feeRate' => '0.0006',
                    'isMaker' => false,
                    'execTime' => '1669196423581',
                ],
            ],
        ],
    ]);

    $result = $mapper->resolveQueryTradeResponse($response);

    expect($result)->toHaveCount(1);
    expect($result[0]['tradeId'])->toBe('2e5a09ee-1234-5678');
    expect($result[0]['orderId'])->toBe('1666a13a-5678');
    expect($result[0]['side'])->toBe('BUY');
    expect($result[0]['price'])->toBe('40000.00');
    expect($result[0]['quantity'])->toBe('0.10');
    expect($result[0]['fee'])->toBe('0.24');
});

// =============================================================================
// MapsMarkPriceQuery Tests
// =============================================================================

test('prepareQueryMarkPriceProperties sets correct properties', function (): void {
    $position = createBybitTestPosition('MARK_PRICE');
    $exchangeSymbol = $position->exchangeSymbol;
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareQueryMarkPriceProperties($exchangeSymbol);

    expect($properties->get('relatable'))->toBe($exchangeSymbol);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
});

test('resolveQueryMarkPriceResponse returns mark price value', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'category' => 'linear',
            'list' => [
                [
                    'symbol' => 'BTCUSDT',
                    'lastPrice' => '40100.00',
                    'indexPrice' => '40098.50',
                    'markPrice' => '40099.75',
                ],
            ],
        ],
    ]);

    $result = $mapper->resolveQueryMarkPriceResponse($response);

    expect($result)->toBe('40099.75');
});

test('resolveQueryMarkPriceResponse returns null when list is empty', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [
            'list' => [],
        ],
    ]);

    $result = $mapper->resolveQueryMarkPriceResponse($response);

    expect($result)->toBeNull();
});

// =============================================================================
// MapsTokenLeverageRatios Tests
// =============================================================================

test('prepareUpdateLeverageRatioProperties sets correct properties', function (): void {
    $position = createBybitTestPosition('LEVERAGE');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareUpdateLeverageRatioProperties($position, 25);

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
    expect($properties->get('options.buyLeverage'))->toBe('25');
    expect($properties->get('options.sellLeverage'))->toBe('25');
});

test('resolveUpdateLeverageRatioResponse parses success response', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [],
        'time' => 1672281607343,
    ]);

    $result = $mapper->resolveUpdateLeverageRatioResponse($response);

    expect($result['success'])->toBeTrue();
});

test('resolveUpdateLeverageRatioResponse handles failure response', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 10001,
        'retMsg' => 'leverage not modified',
        'result' => [],
    ]);

    $result = $mapper->resolveUpdateLeverageRatioResponse($response);

    expect($result['success'])->toBeFalse();
});

// =============================================================================
// MapsSymbolMarginType Tests
// =============================================================================

test('prepareUpdateMarginTypeProperties sets correct properties', function (): void {
    $position = createBybitTestPosition('MARGIN_TYPE');
    $mapper = new BybitApiDataMapper;

    $properties = $mapper->prepareUpdateMarginTypeProperties($position);

    // tradeMode: isolated = 1, crossed = 0 (account default is 'isolated')
    $expectedTradeMode = $position->account->margin_mode === 'isolated' ? 1 : 0;

    // Leverage comes from account based on direction (defaults to position_leverage_long/short = 1)
    $expectedLeverage = (string) match ($position->direction) {
        'LONG' => $position->account->position_leverage_long,
        'SHORT' => $position->account->position_leverage_short,
        default => 10,
    };

    expect($properties->get('relatable'))->toBe($position);
    expect($properties->get('options.symbol'))->toBe('BTCUSDT');
    expect($properties->get('options.category'))->toBe('linear');
    expect($properties->get('options.tradeMode'))->toBe($expectedTradeMode);
    expect($properties->get('options.buyLeverage'))->toBe($expectedLeverage);
    expect($properties->get('options.sellLeverage'))->toBe($expectedLeverage);
});

test('resolveUpdateMarginTypeResponse parses success response', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [],
        'time' => 1672281607343,
    ]);

    $result = $mapper->resolveUpdateMarginTypeResponse($response);

    expect($result['success'])->toBeTrue();
});

// =============================================================================
// Core Mapping Functions Tests
// =============================================================================

test('canonicalOrderType maps Bybit order types correctly', function (): void {
    $mapper = new BybitApiDataMapper;

    expect($mapper->canonicalOrderType(['orderType' => 'Market']))->toBe('MARKET');
    expect($mapper->canonicalOrderType(['orderType' => 'Limit']))->toBe('LIMIT');
    expect($mapper->canonicalOrderType(['stopOrderType' => 'StopLoss']))->toBe('STOP_MARKET');
    expect($mapper->canonicalOrderType(['stopOrderType' => 'TakeProfit']))->toBe('TAKE_PROFIT');
    expect($mapper->canonicalOrderType(['orderType' => 'Unknown']))->toBe('UNKNOWN');
});

test('identifyBaseAndQuote parses Bybit symbols correctly', function (): void {
    $mapper = new BybitApiDataMapper;

    $btcUsdt = $mapper->identifyBaseAndQuote('BTCUSDT');
    expect($btcUsdt['base'])->toBe('BTC');
    expect($btcUsdt['quote'])->toBe('USDT');

    $ethUsdc = $mapper->identifyBaseAndQuote('ETHUSDC');
    expect($ethUsdc['base'])->toBe('ETH');
    expect($ethUsdc['quote'])->toBe('USDC');

    // PERP suffix for USDC-settled perpetuals
    $bnbPerp = $mapper->identifyBaseAndQuote('BNBPERP');
    expect($bnbPerp['base'])->toBe('BNB');
    expect($bnbPerp['quote'])->toBe('USDC');
});

test('baseWithQuote formats Bybit symbols correctly', function (): void {
    $mapper = new BybitApiDataMapper;

    expect($mapper->baseWithQuote('BTC', 'USDT'))->toBe('BTCUSDT');
    expect($mapper->baseWithQuote('ETH', 'USDT'))->toBe('ETHUSDT');
    // USDC-settled uses PERP suffix
    expect($mapper->baseWithQuote('BNB', 'USDC'))->toBe('BNBPERP');
});

test('sideType converts canonical sides to Bybit format', function (): void {
    $mapper = new BybitApiDataMapper;

    expect($mapper->sideType('BUY'))->toBe('Buy');
    expect($mapper->sideType('SELL'))->toBe('Sell');
});

test('directionType converts canonical directions to Bybit format', function (): void {
    $mapper = new BybitApiDataMapper;

    expect($mapper->directionType('LONG'))->toBe('LONG');
    expect($mapper->directionType('SHORT'))->toBe('SHORT');
});

test('long returns uppercase LONG', function (): void {
    $mapper = new BybitApiDataMapper;

    expect($mapper->long())->toBe('LONG');
});

test('short returns uppercase SHORT', function (): void {
    $mapper = new BybitApiDataMapper;

    expect($mapper->short())->toBe('SHORT');
});

// =============================================================================
// Edge Cases Tests
// =============================================================================

test('resolvers handle empty data gracefully', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
        'result' => [],
    ]);

    $placeResult = $mapper->resolvePlaceOrderResponse($response);
    expect($placeResult['orderId'])->toBeNull();

    $cancelResult = $mapper->resolveCancelOrdersResponse($response);
    expect($cancelResult['cancelledOrderIds'])->toBeEmpty();

    $tradesResult = $mapper->resolveQueryTradeResponse($response);
    expect($tradesResult)->toBeEmpty();
});

test('resolvers handle missing result key gracefully', function (): void {
    $mapper = new BybitApiDataMapper;
    $response = createBybitMockResponse([
        'retCode' => 0,
    ]);

    $placeResult = $mapper->resolvePlaceOrderResponse($response);
    expect($placeResult['orderId'])->toBeNull();
});

test('sideType throws exception for invalid side', function (): void {
    $mapper = new BybitApiDataMapper;

    expect(function () use ($mapper): void {
        $mapper->sideType('INVALID');
    })->toThrow(InvalidArgumentException::class);
});

test('directionType throws exception for invalid direction', function (): void {
    $mapper = new BybitApiDataMapper;

    expect(function () use ($mapper): void {
        $mapper->directionType('INVALID');
    })->toThrow(InvalidArgumentException::class);
});

test('identifyBaseAndQuote throws exception for invalid symbol format', function (): void {
    $mapper = new BybitApiDataMapper;

    expect(function () use ($mapper): void {
        $mapper->identifyBaseAndQuote('INVALID');
    })->toThrow(InvalidArgumentException::class);
});
