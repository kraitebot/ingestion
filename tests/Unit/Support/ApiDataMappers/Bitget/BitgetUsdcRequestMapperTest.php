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

/** @return array{account: Account, apiSystem: ApiSystem, exchangeSymbol: ExchangeSymbol, position: Position, order: Order} */
function bitgetUsdcRequestFixture(
    ?string $portfolioQuote = 'USDC',
    ?string $tradingQuote = 'USDC',
    ?string $symbolQuote = 'USDC'
): array {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget USDC Request Test',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'BTC']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'asset' => $symbolQuote === 'USDC' ? 'BTCPERP' : 'BTC'.($symbolQuote ?? ''),
        'token' => 'BTC',
        'quote' => $symbolQuote ?? '',
        'price_precision' => 1,
        'quantity_precision' => 4,
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'portfolio_quote' => $portfolioQuote,
        'trading_quote' => $tradingQuote,
        'margin_mode' => 'isolated',
        'on_hedge_mode' => true,
    ]);
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'total_limit_orders' => 4,
    ]);
    $order = Order::create([
        'position_id' => $position->id,
        'exchange_order_id' => 'USDC-ORDER-1',
        'client_order_id' => 'USDC-CLIENT-1',
        'side' => 'BUY',
        'type' => 'LIMIT',
        'price' => '50000.1',
        'quantity' => '0.0012',
        'position_side' => 'LONG',
    ]);

    return compact('account', 'apiSystem', 'exchangeSymbol', 'position', 'order');
}

function expectBitgetUsdcProduct(mixed $properties, bool $expectsMarginCoin = false): void
{
    expect($properties->get('options.productType'))->toBe('USDC-FUTURES');

    if ($expectsMarginCoin) {
        expect($properties->get('options.marginCoin'))->toBe('USDC');
    }
}

it('uses portfolio quote for Bitget balance and account-wide equity reads', function (): void {
    ['account' => $account] = bitgetUsdcRequestFixture(
        portfolioQuote: 'USDC',
        tradingQuote: 'USDT',
        symbolQuote: 'USDT'
    );
    $mapper = new BitgetApiDataMapper;

    expectBitgetUsdcProduct($mapper->prepareGetBalanceProperties($account));
    expectBitgetUsdcProduct($mapper->prepareQueryAccountProperties($account));
});

it('uses trading quote for Bitget account-wide trading reads', function (): void {
    ['account' => $account] = bitgetUsdcRequestFixture();
    $mapper = new BitgetApiDataMapper;

    expectBitgetUsdcProduct($mapper->prepareQueryPositionsProperties($account));
    expectBitgetUsdcProduct($mapper->prepareQueryOpenOrdersProperties($account));
    expectBitgetUsdcProduct($mapper->prepareQueryPlanOrdersProperties($account));
    expectBitgetUsdcProduct($mapper->prepareQuerySymbolConfigProperties($account));
});

it('maps a USDC Bitget account response instead of filtering for USDT', function (): void {
    $response = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'code' => '00000',
        'msg' => 'success',
        'data' => [[
            'marginCoin' => 'USDC',
            'accountEquity' => '125.50',
            'unrealizedPL' => '2.25',
            'locked' => '10.00',
            'available' => '115.50',
        ]],
    ]));

    expect((new BitgetApiDataMapper)->resolveQueryAccountResponse($response))->toBe([
        'totalWalletBalance' => '125.50',
        'totalUnrealizedProfit' => '2.25',
        'totalMaintMargin' => '10.00',
        'totalMarginBalance' => '125.50',
        'availableFunds' => '115.50',
        'initialMargin' => '10.00',
    ]);
});

it('maps the UTA account assets envelope into the shared account shape', function (): void {
    $response = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'code' => '00000',
        'msg' => 'success',
        'data' => [
            'accountEquity' => '311.50',
            'unrealisedPnl' => '3.25',
            'effEquity' => '204.75',
            'mmr' => '12.50',
            'imr' => '94.20',
            'assets' => [
                ['coin' => 'USDT', 'equity' => '100.00', 'available' => '60.00'],
                ['coin' => 'BGB', 'equity' => '1.15582129', 'available' => '1.15582129'],
            ],
        ],
    ]));

    expect((new BitgetApiDataMapper)->resolveQueryAccountResponse($response))->toBe([
        'totalWalletBalance' => '311.50',
        'totalUnrealizedProfit' => '3.25',
        'totalMaintMargin' => '12.50',
        'totalMarginBalance' => '311.50',
        'availableFunds' => '204.75',
        'initialMargin' => '94.20',
    ]);
});

it('uses the exchange-symbol quote for Bitget klines mark price and leverage brackets', function (): void {
    ['apiSystem' => $apiSystem, 'exchangeSymbol' => $exchangeSymbol] = bitgetUsdcRequestFixture();
    $mapper = new BitgetApiDataMapper;

    expectBitgetUsdcProduct($mapper->prepareQueryKlinesProperties($exchangeSymbol, '15m', null, null, 10));
    expectBitgetUsdcProduct($mapper->prepareQueryMarkPriceProperties($exchangeSymbol));
    expectBitgetUsdcProduct($mapper->prepareQueryLeverageBracketsDataProperties(
        $apiSystem,
        $exchangeSymbol->asset,
        $exchangeSymbol->quote
    ));
});

it('uses the exact Bitget catalogue asset for every USDC symbol request', function (): void {
    [
        'apiSystem' => $apiSystem,
        'exchangeSymbol' => $exchangeSymbol,
        'position' => $position,
        'order' => $order,
    ] = bitgetUsdcRequestFixture();
    $mapper = new BitgetApiDataMapper;
    $order->update(['type' => 'STOP-MARKET']);

    $properties = [
        $mapper->prepareQueryKlinesProperties($exchangeSymbol),
        $mapper->prepareQueryMarkPriceProperties($exchangeSymbol),
        $mapper->prepareQueryLeverageBracketsDataProperties($apiSystem, $exchangeSymbol->asset, $exchangeSymbol->quote),
        $mapper->preparePlaceOrderProperties($order),
        $mapper->prepareOrderQueryProperties($order),
        $mapper->prepareOrderModifyProperties($order, '0.0024', '49900.1'),
        $mapper->prepareOrderCancelProperties($order),
        $mapper->preparePlacePlanOrderProperties($order),
        $mapper->preparePlanOrderQueryProperties($order),
        $mapper->preparePlanOrderCancelProperties($order),
        $mapper->preparePlaceTpslOrderProperties($order),
        $mapper->preparePlacePosTpslProperties($position, '60000', '40000'),
        $mapper->prepareModifyTpslOrderProperties($order, '41000'),
        $mapper->prepareUpdateLeverageRatioProperties($position, 10),
        $mapper->prepareUpdateMarginTypeProperties($position),
        $mapper->prepareCancelOrdersProperties($position),
        $mapper->prepareQueryTokenTradesProperties($position),
    ];

    foreach ($properties as $requestProperties) {
        expect($requestProperties->get('options.symbol'))->toBe('BTCPERP');
    }
});

it('uses the order symbol quote across the regular Bitget order lifecycle', function (): void {
    ['order' => $order] = bitgetUsdcRequestFixture();
    $mapper = new BitgetApiDataMapper;

    expectBitgetUsdcProduct($mapper->preparePlaceOrderProperties($order), true);
    expectBitgetUsdcProduct($mapper->prepareOrderQueryProperties($order));
    expectBitgetUsdcProduct($mapper->prepareOrderModifyProperties($order, '0.0024', '49900.1'), true);
    expectBitgetUsdcProduct($mapper->prepareOrderCancelProperties($order), true);
});

it('uses the order symbol quote across Bitget plan and TP-SL operations', function (): void {
    ['order' => $order, 'position' => $position] = bitgetUsdcRequestFixture();
    $order->update(['type' => 'STOP-MARKET']);
    $mapper = new BitgetApiDataMapper;

    expectBitgetUsdcProduct($mapper->preparePlacePlanOrderProperties($order), true);
    expectBitgetUsdcProduct($mapper->preparePlanOrderQueryProperties($order));
    expectBitgetUsdcProduct($mapper->preparePlanOrderCancelProperties($order), true);
    expectBitgetUsdcProduct($mapper->preparePlaceTpslOrderProperties($order), true);
    expectBitgetUsdcProduct($mapper->preparePlacePosTpslProperties($position, '60000', '40000'), true);
    expectBitgetUsdcProduct($mapper->prepareModifyTpslOrderProperties($order, '41000'), true);
});

it('uses Bitget current orderIdList contract when cancelling a plan order', function (): void {
    ['order' => $order] = bitgetUsdcRequestFixture();
    $order->update(['type' => 'STOP-MARKET']);
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->preparePlanOrderCancelProperties($order);

    expect($properties->get('options.orderIdList'))->toBe([[
        'orderId' => 'USDC-ORDER-1',
        'clientOid' => '',
    ]])->and($properties->has('options.orderId'))->toBeFalse();

    $response = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'code' => '00000',
        'data' => [
            'successList' => [['orderId' => 'USDC-ORDER-1', 'clientOid' => '']],
            'failureList' => [],
        ],
    ]));

    expect($mapper->resolvePlanOrderCancelResponse($response))->toMatchArray([
        'order_id' => 'USDC-ORDER-1',
        'status' => 'CANCELLED',
    ]);
});

it('uses the position symbol quote for Bitget leverage margin cancellation and trade history', function (): void {
    ['position' => $position] = bitgetUsdcRequestFixture();
    $mapper = new BitgetApiDataMapper;

    expectBitgetUsdcProduct($mapper->prepareUpdateLeverageRatioProperties($position, 10), true);
    expectBitgetUsdcProduct($mapper->prepareUpdateMarginTypeProperties($position), true);
    expectBitgetUsdcProduct($mapper->prepareCancelOrdersProperties($position), true);
    expectBitgetUsdcProduct($mapper->prepareQueryTokenTradesProperties($position));
});

it('preserves existing USDT context for Bitget requests', function (): void {
    ['account' => $account, 'order' => $order] = bitgetUsdcRequestFixture(
        portfolioQuote: 'USDT',
        tradingQuote: 'USDT',
        symbolQuote: 'USDT'
    );
    $mapper = new BitgetApiDataMapper;
    $balance = $mapper->prepareGetBalanceProperties($account);
    $placement = $mapper->preparePlaceOrderProperties($order);

    expect($balance->get('options.productType'))->toBe('USDT-FUTURES')
        ->and($placement->get('options.productType'))->toBe('USDT-FUTURES')
        ->and($placement->get('options.marginCoin'))->toBe('USDT');
});

it('rejects absent account quotes instead of inferring Bitget USDT futures', function (): void {
    ['account' => $account] = bitgetUsdcRequestFixture(
        portfolioQuote: null,
        tradingQuote: null,
        symbolQuote: 'USDC'
    );
    $mapper = new BitgetApiDataMapper;

    expect(fn () => $mapper->prepareGetBalanceProperties($account))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Bitget futures quote [null]')
        ->and(fn () => $mapper->prepareQueryPositionsProperties($account))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Bitget futures quote [null]');
});

it('does not replace a missing portfolio quote with the trading quote for balance reads', function (): void {
    ['account' => $account] = bitgetUsdcRequestFixture(
        portfolioQuote: null,
        tradingQuote: 'USDC',
        symbolQuote: 'USDC'
    );
    $mapper = new BitgetApiDataMapper;

    expect(fn () => $mapper->prepareGetBalanceProperties($account))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Bitget futures quote [null]')
        ->and(fn () => $mapper->prepareQueryAccountProperties($account))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Bitget futures quote [null]');
});

it('rejects unsupported symbol quotes across Bitget trading requests', function (): void {
    ['order' => $order, 'exchangeSymbol' => $exchangeSymbol] = bitgetUsdcRequestFixture(
        portfolioQuote: 'USDC',
        tradingQuote: 'USDC',
        symbolQuote: 'EURC'
    );
    $mapper = new BitgetApiDataMapper;

    expect(fn () => $mapper->preparePlaceOrderProperties($order))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Bitget futures quote [EURC]')
        ->and(fn () => $mapper->prepareQueryKlinesProperties($exchangeSymbol))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Bitget futures quote [EURC]');
});
