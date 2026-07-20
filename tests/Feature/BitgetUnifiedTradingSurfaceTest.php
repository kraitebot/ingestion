<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Order\Bitget\ModifyAlgoOrderJob;
use Kraite\Core\Jobs\Atomic\Order\Bitget\PlacePositionTpslJob;
use Kraite\Core\Jobs\Atomic\Position\Bitget\FetchAccountPositionsPnlJob;
use Kraite\Core\Jobs\Atomic\Position\Bitget\SyncPositionModeJob;
use Kraite\Core\Jobs\Atomic\Position\SetMarginModeJob;
use Kraite\Core\Jobs\Recovery\RecoverAccountPositionsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Recovery\BitgetPositionRecoverer;
use Kraite\Core\Support\Recovery\RecoveryReport;
use StepDispatcher\Models\Step;

/** @return array{account: Account, exchangeSymbol: ExchangeSymbol, position: Position} */
function bitgetUnifiedTradingFixture(
    bool $hedgeMode,
    string $direction = 'LONG',
    string $quote = 'USDT',
): array {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget UTA Trading Test',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'BTC']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'asset' => $quote === 'USDC' ? 'BTCPERP' : 'BTCUSDT',
        'token' => 'BTC',
        'quote' => $quote,
        'price_precision' => 1,
        'quantity_precision' => 3,
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'portfolio_quote' => $quote,
        'trading_quote' => $quote,
        'margin_mode' => 'isolated',
        'on_hedge_mode' => $hedgeMode,
        'bitget_account_mode' => 'unified',
        'bitget_api_key' => 'UTA_TEST_KEY',
        'bitget_api_secret' => 'UTA_TEST_SECRET',
        'bitget_passphrase' => 'UTA_TEST_PASSPHRASE',
    ]);
    $position = Position::factory()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $quote === 'USDC' ? 'BTCPERP' : 'BTCUSDT',
        'direction' => $direction,
        'leverage' => 20,
        'total_limit_orders' => 4,
    ]);

    return compact('account', 'exchangeSymbol', 'position');
}

function bitgetUnifiedBody(Request $request): array
{
    $body = $request->body();

    return $body === '' ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
}

it('places a one-way UTA entry with v3 names and no classic fields', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false);
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'MARKET',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'quantity' => '0.123',
        'client_order_id' => 'uta-one-way-entry',
    ]);

    Http::fake(['*' => Http::response(['code' => '00000', 'data' => ['orderId' => 'UTA-ENTRY-1']])]);

    $order->apiPlaceDefault();

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/place-order')
            && $body === [
                'symbol' => 'BTCUSDT',
                'marginMode' => 'isolated',
                'side' => 'buy',
                'clientOid' => 'uta-one-way-entry',
                'orderType' => 'market',
                'category' => 'USDT-FUTURES',
                'qty' => '0.123',
            ];
    });
});

it('normalizes an auto-generated UTA client order id to the exchange limit', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false);
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'MARKET',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'quantity' => '0.123',
    ]);
    $generatedClientOrderId = (string) $order->client_order_id;

    expect(mb_strlen($generatedClientOrderId))->toBe(36);

    Http::fake(function (Request $request) {
        $body = bitgetUnifiedBody($request);

        return Http::response([
            'code' => '00000',
            'data' => ['orderId' => 'UTA-AUTO-CLIENT-1', 'clientOid' => $body['clientOid']],
        ]);
    });

    $order->apiPlaceDefault();

    $persistedClientOrderId = (string) $order->fresh()->client_order_id;

    expect(mb_strlen($persistedClientOrderId))->toBe(32)
        ->and($persistedClientOrderId)->toBe(str_replace('-', '', $generatedClientOrderId));

    Http::assertSent(function (Request $request) use ($persistedClientOrderId): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/place-order')
            && $body['clientOid'] === $persistedClientOrderId;
    });
});

it('uses the USDC UTA category while preserving the catalogue symbol', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(
        hedgeMode: false,
        quote: 'USDC',
    );
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'MARKET',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'quantity' => '0.123',
        'client_order_id' => 'uta-usdc-entry',
    ]);
    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['orderId' => 'UTA-USDC-ENTRY-1'],
    ])]);

    $order->apiPlaceDefault();

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/place-order')
            && $body['category'] === 'USDC-FUTURES'
            && $body['symbol'] === 'BTCPERP'
            && ! array_key_exists('marginCoin', $body)
            && ! array_key_exists('productType', $body);
    });
});

it('places hedge UTA closes with the execution side and explicit position side', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'MARKET-CANCEL',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.321',
        'client_order_id' => 'uta-hedge-close',
    ]);

    Http::fake(['*' => Http::response(['code' => '00000', 'data' => ['orderId' => 'UTA-CLOSE-1']])]);

    $order->apiPlaceDefault();

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/place-order')
            && $body['side'] === 'sell'
            && $body['posSide'] === 'long'
            && $body['reduceOnly'] === 'yes'
            && ! array_key_exists('tradeSide', $body)
            && ! array_key_exists('marginCoin', $body)
            && ! array_key_exists('productType', $body)
            && ! array_key_exists('size', $body);
    });
});

it('does not mark an opening UTA trigger strategy as reduce only', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'LIMIT',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'quantity' => '0.123',
        'price' => '59000',
        'client_order_id' => 'uta-trigger-entry',
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/unfilled-strategy-orders')) {
            return Http::response([
                'code' => '00000',
                'data' => [[
                    'orderId' => 'UTA-TRIGGER-ENTRY-1',
                    'clientOid' => 'uta-trigger-entry',
                    'symbol' => 'BTCUSDT',
                    'status' => 'pending',
                    'triggerPrice' => '59000',
                    'qty' => '0.123',
                    'side' => 'buy',
                    'posSide' => 'long',
                ]],
            ]);
        }

        return Http::response([
            'code' => '00000',
            'data' => ['orderId' => 'UTA-TRIGGER-ENTRY-1', 'clientOid' => 'uta-trigger-entry'],
        ]);
    });

    $order->apiPlacePlanOrder();
    $query = $order->fresh()->apiQueryPlanOrder();

    expect($query->result['status'])->toBe('NEW');

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/place-strategy-order')
            && $body['type'] === 'trigger'
            && $body['side'] === 'buy'
            && $body['posSide'] === 'long'
            && ! array_key_exists('reduceOnly', $body)
            && ! array_key_exists('tradeSide', $body);
    });
    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/unfilled-strategy-orders?category=USDT-FUTURES',
    ) && ! str_contains($request->url(), 'type='));
});

it('queries all UTA strategy families for account synchronization', function (): void {
    ['account' => $account] = bitgetUnifiedTradingFixture(hedgeMode: true);
    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => [
            [
                'orderId' => 'UTA-ACCOUNT-TPSL',
                'symbol' => 'BTCUSDT',
                'status' => 'pending',
                'stopLoss' => '57000',
                'qty' => '0.1',
                'posSide' => 'long',
            ],
            [
                'orderId' => 'UTA-ACCOUNT-TRIGGER',
                'symbol' => 'ETHUSDT',
                'status' => 'pending',
                'triggerPrice' => '3000',
                'qty' => '1',
                'posSide' => 'short',
            ],
        ],
    ])]);

    $orders = $account->apiQueryPlanOrders()->result;
    $triggerOrder = collect($orders)->firstWhere('orderId', 'UTA-ACCOUNT-TRIGGER');

    expect($orders)->toHaveCount(2)
        ->and(collect($orders)->pluck('orderId'))->toContain(
            'UTA-ACCOUNT-TPSL',
            'UTA-ACCOUNT-TRIGGER',
        )
        ->and($triggerOrder['_orderType'])->toBe('STOP_MARKET')
        ->and($triggerOrder['_price'])->toBe('3000');

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/unfilled-strategy-orders?category=USDT-FUTURES',
    ) && ! str_contains($request->url(), 'type='));
});

it('prefetches UTA strategy orders during account recovery', function (): void {
    ['account' => $account] = bitgetUnifiedTradingFixture(hedgeMode: true);
    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/unfilled-orders') => Http::response([
                'code' => '00000',
                'data' => ['list' => []],
            ]),
            str_contains($request->url(), '/unfilled-strategy-orders') => Http::response([
                'code' => '00000',
                'data' => [[
                    'orderId' => 'UTA-RECOVERY-TPSL',
                    'symbol' => 'BTCUSDT',
                    'status' => 'pending',
                    'stopLoss' => '57000',
                    'qty' => '0.1',
                    'posSide' => 'long',
                ]],
            ]),
            default => Http::response(['code' => '99999', 'msg' => 'Unexpected endpoint'], 500),
        };
    });

    $job = new RecoverAccountPositionsJob($account->id);
    $job->step = new Step;
    $job->step->id = 1;
    $prefetch = new ReflectionMethod($job, 'prefetchOrderBatches');

    [$openOrders, $strategyOrders] = $prefetch->invoke($job);

    expect($openOrders)->toBe([])
        ->and($strategyOrders)->toHaveCount(1)
        ->and($strategyOrders[0]['orderId'])->toBe('UTA-RECOVERY-TPSL');
    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/unfilled-strategy-orders?category=USDT-FUTURES',
    ));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/algo'));
});

it('maps batched UTA recovery orders only to the requested hedge side', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol, 'position' => $longPosition] = bitgetUnifiedTradingFixture(
        hedgeMode: true,
        direction: 'LONG',
    );
    $shortPosition = $longPosition->replicate();
    $shortPosition->direction = 'SHORT';
    $shortPosition->setRelation('exchangeSymbol', $exchangeSymbol);
    $recoverer = (new BitgetPositionRecoverer($account, new RecoveryReport))
        ->withBatchedOrders(
            [
                ['orderId' => 'UTA-REG-LONG', 'symbol' => 'BTCUSDT', 'posSide' => 'long', 'orderStatus' => 'live', 'qty' => '0.1'],
                ['orderId' => 'UTA-REG-SHORT', 'symbol' => 'BTCUSDT', 'posSide' => 'short', 'orderStatus' => 'live', 'qty' => '0.2'],
            ],
            [
                ['orderId' => 'UTA-TP-LONG', 'symbol' => 'BTCUSDT', 'posSide' => 'long', 'status' => 'pending', 'takeProfit' => '65000'],
                ['orderId' => 'UTA-SL-SHORT', 'symbol' => 'BTCUSDT', 'posSide' => 'short', 'status' => 'pending', 'stopLoss' => '65000'],
            ],
        );
    $fetch = new ReflectionMethod($recoverer, 'fetchOpenOrders');

    $longOrders = $fetch->invoke($recoverer, $longPosition, ['symbol' => 'BTCUSDT']);
    $shortOrders = $fetch->invoke($recoverer, $shortPosition, ['symbol' => 'BTCUSDT']);

    expect(collect($longOrders)->pluck('orderId')->all())->toBe(['UTA-REG-LONG', 'UTA-TP-LONG'])
        ->and(collect($shortOrders)->pluck('orderId')->all())->toBe(['UTA-REG-SHORT', 'UTA-SL-SHORT'])
        ->and(collect($longOrders)->firstWhere('orderId', 'UTA-TP-LONG')['side'])->toBe('sell')
        ->and(collect($shortOrders)->firstWhere('orderId', 'UTA-SL-SHORT')['side'])->toBe('buy');
    Http::assertNothingSent();
});

it('aborts Bitget orphan reconciliation when the UTA strategy catalogue fails', function (): void {
    ['account' => $account] = bitgetUnifiedTradingFixture(hedgeMode: true);
    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/unfilled-orders') => Http::response([
                'code' => '00000',
                'data' => ['list' => []],
            ]),
            str_contains($request->url(), '/unfilled-strategy-orders') => Http::response([
                'code' => '40010',
                'msg' => 'Request timed out',
            ], 500),
            str_contains($request->url(), '/current-position') => Http::response([
                'code' => '00000',
                'data' => ['list' => []],
            ]),
            default => Http::response(['code' => '99999', 'msg' => 'Unexpected endpoint'], 500),
        };
    });

    $command = app(CheckSystemHealthCommand::class);
    $reconcile = Closure::bind(
        fn (Account $target): int => $this->reconcileAccountOrphans($target),
        $command,
        CheckSystemHealthCommand::class,
    );

    expect(fn (): int => $reconcile($account))->toThrow(Illuminate\Http\Client\RequestException::class);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/current-position'));
});

it('uses UTA account settings for position mode and symbol configuration', function (): void {
    ['account' => $account, 'position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false);

    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => [
            'holdMode' => 'hedge_mode',
            'symbolConfigList' => [[
                'category' => 'USDT-FUTURES',
                'symbol' => 'BTCUSDT',
                'marginMode' => 'isolated',
                'leverage' => '20',
            ]],
        ],
    ])]);

    $mode = (new SyncPositionModeJob($position->id))->computeApiable();
    $configs = $account->apiQuerySymbolConfig()->result;

    expect($mode['position_mode'])->toBe('hedge_mode')
        ->and($account->fresh()->on_hedge_mode)->toBeTrue()
        ->and($configs['BTCUSDT'])->toBe([
            'symbol' => 'BTCUSDT',
            'leverage' => 20,
            'marginType' => 'ISOLATED',
        ]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/account/settings')
        && ! str_contains($request->url(), 'productType=')
        && ! str_contains($request->url(), 'marginCoin='));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/'));
});

it('parses each UTA hedge side independently when the exchange returns both', function (): void {
    ['account' => $account] = bitgetUnifiedTradingFixture(hedgeMode: true);
    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['list' => [
            [
                'symbol' => 'BTCUSDT',
                'total' => '0.1',
                'posSide' => 'long',
                'holdMode' => 'hedge_mode',
                'breakEvenPrice' => '60000',
            ],
            [
                'symbol' => 'BTCUSDT',
                'total' => '0.2',
                'posSide' => 'short',
                'holdMode' => 'hedge_mode',
                'breakEvenPrice' => '61000',
            ],
        ]],
    ])]);

    $positions = $account->apiQueryPositions()->result;

    expect($positions)->toHaveKeys(['BTCUSDT:LONG', 'BTCUSDT:SHORT'])
        ->and($positions['BTCUSDT:LONG']['positionAmt'])->toBe(0.1)
        ->and($positions['BTCUSDT:SHORT']['positionAmt'])->toBe(-0.2);

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/position/current-position?category=USDT-FUTURES',
    ));
});

it('sets UTA leverage with category margin mode and hedge side', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'SHORT');

    Http::fake(['*' => Http::response(['code' => '00000', 'data' => ['leverage' => '25']])]);

    $position->apiUpdateLeverageRatio(25);

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/account/set-leverage')
            && $body['symbol'] === 'BTCUSDT'
            && $body['leverage'] === '25'
            && $body['category'] === 'USDT-FUTURES'
            && $body['marginMode'] === 'isolated'
            && $body['posSide'] === 'short'
            && ! array_key_exists('productType', $body)
            && ! array_key_exists('marginCoin', $body)
            && ! array_key_exists('holdSide', $body);
    });
});

it('sets the UTA position side for isolated one-way leverage', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false, direction: 'LONG');
    Http::fake(['*' => Http::response(['code' => '00000', 'data' => ['leverage' => '25']])]);

    $position->apiUpdateLeverageRatio(25);

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/account/set-leverage')
            && $body['marginMode'] === 'isolated'
            && $body['posSide'] === 'long';
    });
});

it('places queries modifies and cancels a UTA position stop strategy', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.123',
        'price' => '59000',
        'client_order_id' => 'uta-stop-client',
        'is_algo' => true,
    ]);
    $expectedStopPrice = (string) api_format_price('59000', $position->exchangeSymbol);
    $expectedModifiedStopPrice = (string) api_format_price('58500', $position->exchangeSymbol);

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/place-strategy-order') => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'UTA-STOP-1', 'clientOid' => 'uta-stop-client'],
            ]),
            str_contains($request->url(), '/unfilled-strategy-orders') => Http::response([
                'code' => '00000',
                'data' => [[
                    'orderId' => 'UTA-STOP-1',
                    'clientOid' => 'uta-stop-client',
                    'symbol' => 'BTCUSDT',
                    'status' => 'pending',
                    'stopLoss' => '59000',
                    'qty' => '0.123',
                    'side' => 'sell',
                    'posSide' => 'long',
                ]],
            ]),
            str_contains($request->url(), '/modify-strategy-order') => Http::response(['code' => '00000', 'data' => null]),
            str_contains($request->url(), '/cancel-strategy-order') => Http::response(['code' => '00000', 'data' => null]),
            default => Http::response(['code' => '99999', 'msg' => 'Unexpected endpoint'], 500),
        };
    });

    $placed = $order->apiPlaceTpslOrder();
    $queried = $order->fresh()->apiQueryPlanOrder();
    $modified = $order->fresh()->apiModifyTpsl('58500');
    $cancelled = $order->fresh()->apiCancelPlanOrder();

    expect($placed->result['orderId'])->toBe('UTA-STOP-1')
        ->and($queried->result['status'])->toBe('NEW')
        ->and($queried->result['price'])->toBe('59000')
        ->and($modified->result['success'])->toBeTrue()
        ->and($cancelled->result['status'])->toBe('CANCELLED');

    Http::assertSent(function (Request $request) use ($expectedStopPrice): bool {
        if (! str_contains($request->url(), '/api/v3/trade/place-strategy-order')) {
            return false;
        }

        $body = bitgetUnifiedBody($request);

        return $body['category'] === 'USDT-FUTURES'
            && $body['type'] === 'tpsl'
            && $body['tpslMode'] === 'full'
            && $body['stopLoss'] === $expectedStopPrice
            && $body['side'] === 'sell'
            && $body['posSide'] === 'long'
            && $body['reduceOnly'] === 'yes'
            && ! array_key_exists('marginCoin', $body);
    });
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/trade/modify-strategy-order')
        && bitgetUnifiedBody($request)['stopLoss'] === $expectedModifiedStopPrice);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/trade/cancel-strategy-order')
        && bitgetUnifiedBody($request)['orderId'] === 'UTA-STOP-1');
});

it('queries modifies and cancels regular UTA orders through v3', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false);
    $order = Order::create([
        'position_id' => $position->id,
        'type' => 'LIMIT',
        'status' => 'NEW',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'quantity' => '0.123',
        'price' => '60000',
        'client_order_id' => 'uta-regular-client',
        'exchange_order_id' => 'UTA-REGULAR-1',
    ]);

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/modify-order') => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'UTA-REGULAR-1', 'clientOid' => 'uta-regular-client'],
            ]),
            str_contains($request->url(), '/order-info') => Http::response([
                'code' => '00000',
                'data' => [
                    'orderId' => 'UTA-REGULAR-1',
                    'symbol' => 'BTCUSDT',
                    'orderStatus' => 'filled',
                    'orderType' => 'limit',
                    'qty' => '0.125',
                    'cumExecQty' => '0.125',
                    'avgPrice' => '60100',
                    'price' => '60050',
                    'side' => 'buy',
                ],
            ]),
            str_contains($request->url(), '/cancel-order') => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'UTA-REGULAR-1', 'clientOid' => 'uta-regular-client'],
            ]),
            default => Http::response(['code' => '99999'], 500),
        };
    });

    $order->apiModify('0.125', '60050');
    $query = $order->apiQueryDefault();
    $cancel = $order->apiCancelDefault();

    expect($query->result['status'])->toBe('FILLED')
        ->and($query->result['quantity'])->toBe('0.125')
        ->and($query->result['price'])->toBe('60100')
        ->and($cancel->result['status'])->toBe('CANCELLED');

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/modify-order')
            && $body['category'] === 'USDT-FUTURES'
            && $body['orderId'] === 'UTA-REGULAR-1'
            && $body['qty'] === '0.125'
            && $body['price'] === '60050'
            && ! array_key_exists('newSize', $body)
            && ! array_key_exists('newPrice', $body)
            && ! array_key_exists('marginCoin', $body);
    });
    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/order-info?orderId=UTA-REGULAR-1',
    ));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/trade/cancel-order')
        && bitgetUnifiedBody($request)['orderId'] === 'UTA-REGULAR-1');
});

it('closes one UTA hedge side and persists the closing fill price', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'SHORT');

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/close-positions')) {
            return Http::response([
                'code' => '00000',
                'data' => ['list' => [[
                    'orderId' => 'UTA-FLASH-CLOSE-1',
                    'clientOid' => 'UTA-FLASH-CLOSE-CLIENT',
                    'code' => '00000',
                    'msg' => 'success',
                ]]],
            ]);
        }

        return Http::response([
            'code' => '00000',
            'data' => ['orderId' => 'UTA-FLASH-CLOSE-1', 'avgPrice' => '60234.5'],
        ]);
    });

    $response = $position->apiCloseBitget();

    expect($response->result['success'])->toBeTrue()
        ->and($response->result['successList'])->toHaveCount(1)
        ->and((string) $position->fresh()->closing_price)->toContain('60234.5');

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/close-positions')
            && $body === [
                'symbol' => 'BTCUSDT',
                'category' => 'USDT-FUTURES',
                'posSide' => 'short',
            ];
    });
});

it('reports a UTA close as failed when Bitget rejects the requested side', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'SHORT');

    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['list' => [[
            'orderId' => '',
            'clientOid' => '',
            'code' => '40757',
            'msg' => 'Not enough position is available',
        ]]],
    ])]);

    $response = $position->apiCloseBitget();

    expect($response->result['success'])->toBeFalse()
        ->and($response->result['successList'])->toBe([])
        ->and($response->result['failureList'])->toHaveCount(1);
});

it('normalizes UTA cancel-all per-order outcomes', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false);

    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['list' => [
            ['orderId' => 'UTA-CANCELLED-1', 'clientOid' => 'client-1', 'code' => '00000', 'msg' => 'success'],
            ['orderId' => 'UTA-FAILED-1', 'clientOid' => 'client-2', 'code' => '40758', 'msg' => 'No order'],
        ]],
    ])]);

    $result = $position->apiCancelOpenOrders()->result;

    expect($result['successList'])->toHaveCount(1)
        ->and($result['successList'][0]['orderId'])->toBe('UTA-CANCELLED-1')
        ->and($result['failureList'])->toHaveCount(1)
        ->and($result['failureList'][0]['orderId'])->toBe('UTA-FAILED-1');

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/cancel-symbol-order',
    ));
});

it('normalizes and locally scopes UTA fills to the position hedge side', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');

    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['list' => [
            [
                'execId' => 'LONG-CLOSE',
                'orderId' => 'ORDER-LONG-CLOSE',
                'symbol' => 'BTCUSDT',
                'execPrice' => '61000',
                'execQty' => '0.1',
                'side' => 'sell',
                'tradeSide' => 'close',
                'createdTime' => '2000',
                'execPnl' => '10',
            ],
            [
                'execId' => 'SHORT-CLOSE',
                'orderId' => 'ORDER-SHORT-CLOSE',
                'symbol' => 'BTCUSDT',
                'execPrice' => '59000',
                'execQty' => '0.2',
                'side' => 'buy',
                'tradeSide' => 'close',
                'createdTime' => '1500',
                'execPnl' => '5',
            ],
            [
                'execId' => 'OTHER-SYMBOL',
                'orderId' => 'ORDER-OTHER',
                'symbol' => 'ETHUSDT',
                'execPrice' => '3000',
                'execQty' => '1',
                'side' => 'sell',
                'tradeSide' => 'close',
                'createdTime' => '1000',
                'execPnl' => '1',
            ],
        ]],
    ])]);

    $fills = $position->apiQueryTokenTrades()->result;

    expect($fills)->toHaveCount(1)
        ->and($fills[0]['tradeId'])->toBe('LONG-CLOSE')
        ->and($fills[0]['price'])->toBe('61000')
        ->and($fills[0]['baseVolume'])->toBe('0.1')
        ->and($fills[0]['side'])->toBe('sell');

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/fills?category=USDT-FUTURES',
    ));
});

it('keeps the native closing side for Classic one-way fills', function (): void {
    ['account' => $account, 'position' => $position] = bitgetUnifiedTradingFixture(
        hedgeMode: false,
        direction: 'LONG',
    );
    $account->update(['bitget_account_mode' => 'classic']);

    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['fillList' => [[
            'tradeId' => 'CLASSIC-ONE-WAY-CLOSE',
            'orderId' => 'CLASSIC-ORDER-CLOSE',
            'symbol' => 'BTCUSDT',
            'price' => '61000',
            'baseVolume' => '0.1',
            'side' => 'sell',
            'tradeSide' => 'close',
            'cTime' => '2000',
        ]]],
    ])]);

    $fills = $position->apiQueryTokenTrades()->result;

    expect($fills)->toHaveCount(1)
        ->and($fills[0]['side'])->toBe('sell');
});

it('stores UTA historical position PnL from the v3 envelope', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol, 'position' => $openPosition]
        = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'SHORT');
    Position::withoutEvents(fn () => $openPosition->delete());
    $openedAt = now()->subHours(2);
    $closedAt = now()->subHour();
    $position = Position::factory()->short()->closed()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'BTCUSDT',
        'direction' => 'SHORT',
        'opened_at' => $openedAt,
        'closed_at' => $closedAt,
        'pnl' => null,
    ]);

    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['list' => [[
            'symbol' => 'BTCUSDT',
            'posSide' => 'short',
            'createdTime' => (string) $openedAt->getTimestampMs(),
            'updatedTime' => (string) $closedAt->getTimestampMs(),
            'netProfit' => '12.34',
        ]]],
    ])]);

    $result = (new FetchAccountPositionsPnlJob($account->id))->computeApiable();

    expect($result['updated'])->toBe(1)
        ->and((string) $position->fresh()->pnl)->toContain('12.34');

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/position/history-position?category=USDT-FUTURES',
    ));
});

it('chunks UTA historical position PnL reads into thirty-day windows', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol, 'position' => $openPosition]
        = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    Position::withoutEvents(fn () => $openPosition->delete());
    foreach ([50, 1] as $daysAgo) {
        Position::factory()->long()->closed()->create([
            'account_id' => $account->id,
            'exchange_symbol_id' => $exchangeSymbol->id,
            'parsed_trading_pair' => 'BTCUSDT',
            'direction' => 'LONG',
            'opened_at' => now()->subDays($daysAgo),
            'closed_at' => now()->subDays($daysAgo)->addHour(),
            'pnl' => null,
        ]);
    }
    Http::fake(['*' => Http::response([
        'code' => '00000',
        'data' => ['list' => []],
    ])]);

    (new FetchAccountPositionsPnlJob($account->id))->computeApiable();

    $historyRequests = collect(Http::recorded())
        ->map(fn (array $exchange): Request => $exchange[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), '/api/v3/position/history-position'))
        ->values();

    expect($historyRequests)->toHaveCount(2);
    $historyRequests->each(function (Request $request): void {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        expect((int) $query['endTime'] - (int) $query['startTime'])
            ->toBeLessThanOrEqual(30 * 24 * 60 * 60 * 1000);
    });
});

it('recovers a UTA position with regular strategy and fill history', function (): void {
    ['account' => $account, 'position' => $existingPosition] = bitgetUnifiedTradingFixture(
        hedgeMode: true,
        direction: 'LONG',
    );
    Position::withoutEvents(fn () => $existingPosition->delete());

    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/current-position') => Http::response([
                'code' => '00000',
                'data' => ['list' => [[
                    'symbol' => 'BTCUSDT',
                    'total' => '0.1',
                    'posSide' => 'long',
                    'holdMode' => 'hedge_mode',
                    'openPriceAvg' => '60000',
                    'breakEvenPrice' => '60010',
                    'leverage' => '20',
                    'marginMode' => 'isolated',
                    'createdTime' => '1000',
                ]]],
            ]),
            str_contains($request->url(), '/unfilled-orders') => Http::response([
                'code' => '00000',
                'data' => ['list' => [[
                    'orderId' => 'UTA-RECOVERY-LIMIT',
                    'clientOid' => 'uta-recovery-limit',
                    'symbol' => 'BTCUSDT',
                    'orderType' => 'limit',
                    'orderStatus' => 'new',
                    'qty' => '0.1',
                    'price' => '59000',
                    'side' => 'buy',
                    'posSide' => 'long',
                    'createdTime' => '1200',
                ]]],
            ]),
            str_contains($request->url(), '/unfilled-strategy-orders') => Http::response([
                'code' => '00000',
                'data' => [
                    [
                        'orderId' => 'UTA-RECOVERY-STOP',
                        'clientOid' => 'uta-recovery-stop',
                        'symbol' => 'BTCUSDT',
                        'status' => 'pending',
                        'qty' => '0.1',
                        'stopLoss' => '57000',
                        'side' => 'sell',
                        'posSide' => 'long',
                        'createdTime' => '1300',
                    ],
                    [
                        'orderId' => 'UTA-RECOVERY-TRIGGER',
                        'clientOid' => 'uta-recovery-trigger',
                        'symbol' => 'ETHUSDT',
                        'status' => 'pending',
                        'qty' => '0.05',
                        'triggerPrice' => '59000',
                        'triggerOrderType' => 'market',
                        'side' => 'sell',
                        'posSide' => 'short',
                        'createdTime' => '1400',
                    ],
                ],
            ]),
            str_contains($request->url(), '/fills') => Http::response([
                'code' => '00000',
                'data' => ['list' => [[
                    'execId' => 'UTA-RECOVERY-FILL',
                    'orderId' => 'UTA-RECOVERY-ENTRY',
                    'clientOid' => 'uta-recovery-entry',
                    'symbol' => 'BTCUSDT',
                    'orderType' => 'market',
                    'execPrice' => '60000',
                    'execQty' => '0.1',
                    'side' => 'buy',
                    'tradeSide' => 'open',
                    'createdTime' => '1100',
                ]]],
            ]),
            default => Http::response(['code' => '99999', 'msg' => 'Unexpected endpoint'], 500),
        };
    });

    $report = new RecoveryReport;
    (new BitgetPositionRecoverer($account, $report))->run();

    $position = Position::query()->where('account_id', $account->id)->sole();

    expect($report->warnings)->toBe([])
        ->and($report->positionsCreated)->toBe(1)
        ->and($report->ordersCreated)->toBe(3)
        ->and($position->orders()->pluck('exchange_order_id'))->toContain(
            'UTA-RECOVERY-ENTRY',
            'UTA-RECOVERY-LIMIT',
            'UTA-RECOVERY-STOP',
        )
        ->and($position->orders()->where('exchange_order_id', 'UTA-RECOVERY-TRIGGER')->exists())->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/mix/'));
    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/unfilled-strategy-orders?category=USDT-FUTURES',
    ) && ! str_contains($request->url(), 'symbol='));
});

it('defers UTA margin mode to leverage and order requests without a fake API mutation', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: false);
    Http::preventStrayRequests();

    $result = (new SetMarginModeJob($position->id))->computeApiable();

    expect($result['margin_mode'])->toBe('isolated')
        ->and($result['api_response'])->toBeNull()
        ->and($result['message'])->toContain('will be applied by the UTA leverage and order requests');
    Http::assertNothingSent();
});

it('places initial UTA take-profit and stop-loss as two independently manageable strategies', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    $position->updateSaving([
        'status' => 'opening',
        'opening_price' => '60000',
        'quantity' => '0.1',
        'profit_percentage' => '5',
        'stop_market_percentage' => '5',
        'total_limit_orders' => 0,
    ]);
    Kraite::query()->findOrFail(1)->update([
        'bitget_api_key' => 'UTA_ADMIN_KEY',
        'bitget_api_secret' => 'UTA_ADMIN_SECRET',
        'bitget_passphrase' => 'UTA_ADMIN_PASSPHRASE',
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/symbol-price')) {
            return Http::response([
                'code' => '00000',
                'data' => [['symbol' => 'BTCUSDT', 'markPrice' => '60000']],
            ]);
        }

        $body = bitgetUnifiedBody($request);
        $isStopLoss = array_key_exists('stopLoss', $body);

        return Http::response([
            'code' => '00000',
            'data' => [
                'orderId' => $isStopLoss ? 'UTA-INITIAL-SL' : 'UTA-INITIAL-TP',
                'clientOid' => $body['clientOid'],
            ],
        ]);
    });

    $job = new PlacePositionTpslJob($position->id);
    $result = $job->computeApiable();

    expect($result['take_profit_id'])->toBe('UTA-INITIAL-TP')
        ->and($result['stop_loss_id'])->toBe('UTA-INITIAL-SL')
        ->and($job->doubleCheck())->toBeTrue()
        ->and($position->orders()->where('type', 'PROFIT-LIMIT')->sole()->exchange_order_id)->toBe('UTA-INITIAL-TP')
        ->and($position->orders()->where('type', 'STOP-MARKET')->sole()->exchange_order_id)->toBe('UTA-INITIAL-SL');

    $strategyRequests = Http::recorded(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/place-strategy-order',
    ))->values();

    expect($strategyRequests)->toHaveCount(2)
        ->and(collect($strategyRequests)->map(
            fn (array $record): array => array_keys(bitgetUnifiedBody($record[0]))
        )->every(fn (array $keys): bool => ! in_array('takeProfit', $keys, true)
            || ! in_array('stopLoss', $keys, true)))->toBeTrue()
        ->and(collect($strategyRequests)->map(
            fn (array $record): int => mb_strlen((string) bitgetUnifiedBody($record[0])['clientOid'])
        )->all())->toBe([32, 32])
        ->and($position->orders()->whereIn('type', ['PROFIT-LIMIT', 'STOP-MARKET'])
            ->pluck('client_order_id')
            ->every(fn (string $clientOrderId): bool => mb_strlen($clientOrderId) === 32))->toBeTrue();
});

it('does not resend a confirmed UTA protection leg when retrying the missing sibling', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    $position->updateSaving([
        'status' => 'opening',
        'opening_price' => '60000',
        'quantity' => '0.1',
        'profit_percentage' => '5',
        'stop_market_percentage' => '5',
        'total_limit_orders' => 0,
    ]);
    Kraite::query()->findOrFail(1)->update([
        'bitget_api_key' => 'UTA_RETRY_ADMIN_KEY',
        'bitget_api_secret' => 'UTA_RETRY_ADMIN_SECRET',
        'bitget_passphrase' => 'UTA_RETRY_ADMIN_PASSPHRASE',
    ]);
    $profitOrder = Order::withoutEvents(fn () => Order::create([
        'uuid' => Str::uuid()->toString(),
        'position_id' => $position->id,
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.1',
        'price' => '63000',
        'client_order_id' => 'uta-retry-profit',
        'exchange_order_id' => 'UTA-RETRY-TP-CONFIRMED',
        'is_algo' => true,
    ]));
    $stopLossOrder = Order::withoutEvents(fn () => Order::create([
        'uuid' => Str::uuid()->toString(),
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.1',
        'price' => '57000',
        'client_order_id' => 'uta-retry-stop',
        'exchange_order_id' => null,
        'is_algo' => true,
    ]));

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/symbol-price')) {
            return Http::response([
                'code' => '00000',
                'data' => [['symbol' => 'BTCUSDT', 'markPrice' => '60000']],
            ]);
        }

        $body = bitgetUnifiedBody($request);

        if (array_key_exists('takeProfit', $body)) {
            throw new RuntimeException('Confirmed take-profit must not be sent again.');
        }

        return Http::response([
            'code' => '00000',
            'data' => ['orderId' => 'UTA-RETRY-SL-NEW', 'clientOid' => $body['clientOid']],
        ]);
    });

    $result = (new PlacePositionTpslJob(
        $position->id,
        $profitOrder->id,
        $stopLossOrder->id,
    ))->computeApiable();

    expect($result['take_profit_id'])->toBe('UTA-RETRY-TP-CONFIRMED')
        ->and($result['stop_loss_id'])->toBe('UTA-RETRY-SL-NEW')
        ->and($profitOrder->fresh()->exchange_order_id)->toBe('UTA-RETRY-TP-CONFIRMED')
        ->and($stopLossOrder->fresh()->exchange_order_id)->toBe('UTA-RETRY-SL-NEW');

    $strategyRequests = Http::recorded(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/place-strategy-order',
    ))->values();

    expect($strategyRequests)->toHaveCount(1)
        ->and(bitgetUnifiedBody($strategyRequests[0][0]))->toHaveKey('stopLoss')
        ->not->toHaveKey('takeProfit');
});

it('repairs only the drifted UTA protection strategy without requiring its sibling', function (): void {
    ['position' => $position] = bitgetUnifiedTradingFixture(hedgeMode: true, direction: 'LONG');
    $position->updateSaving(['status' => 'active']);
    $profitOrder = Order::withoutEvents(fn () => Order::create([
        'uuid' => Str::uuid()->toString(),
        'position_id' => $position->id,
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.1',
        'price' => '62000',
        'reference_price' => '63000',
        'client_order_id' => 'uta-drift-profit',
        'exchange_order_id' => 'UTA-DRIFT-PROFIT-1',
        'is_algo' => true,
    ]));
    Http::fake(['*' => Http::response(['code' => '00000', 'data' => null])]);

    $job = new ModifyAlgoOrderJob($position->id, $profitOrder->id);

    expect($job->startOrFail())->toBeTrue();
    $result = $job->computeApiable();
    $job->complete();

    expect($result['new_price'])->toContain('63000')
        ->and((string) $profitOrder->fresh()->price)->toContain('63000');

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/modify-strategy-order')
            && $body['orderId'] === 'UTA-DRIFT-PROFIT-1'
            && array_key_exists('takeProfit', $body)
            && ! array_key_exists('stopLoss', $body);
    });
    Http::assertSentCount(1);
});

it('applies WAP by resizing only the UTA take-profit strategy', function (): void {
    Notification::fake();
    ['account' => $account, 'position' => $position] = bitgetUnifiedTradingFixture(
        hedgeMode: true,
        direction: 'LONG',
    );
    $position->updateSaving([
        'status' => 'waping',
        'profit_percentage' => '5',
        'quantity' => '0.1',
    ]);
    Order::withoutEvents(fn () => Order::create([
        'uuid' => Str::uuid()->toString(),
        'position_id' => $position->id,
        'type' => 'MARKET',
        'status' => 'FILLED',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'quantity' => '0.1',
        'price' => '60000',
        'client_order_id' => 'uta-wap-entry',
        'exchange_order_id' => 'UTA-WAP-ENTRY-1',
    ]));
    $profitOrder = Order::withoutEvents(fn () => Order::create([
        'uuid' => Str::uuid()->toString(),
        'position_id' => $position->id,
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.05',
        'price' => '62000',
        'reference_price' => '62000',
        'client_order_id' => 'uta-wap-profit',
        'exchange_order_id' => 'UTA-WAP-PROFIT-1',
        'is_algo' => true,
    ]));
    $stopOrder = Order::withoutEvents(fn () => Order::create([
        'uuid' => Str::uuid()->toString(),
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'quantity' => '0.1',
        'price' => '57000',
        'reference_price' => '57000',
        'client_order_id' => 'uta-wap-stop',
        'exchange_order_id' => 'UTA-WAP-STOP-1',
        'is_algo' => true,
    ]));
    ApiSnapshot::storeFor($account, 'account-positions', [
        'BTCUSDT:LONG' => [
            'symbol' => 'BTCUSDT',
            'posSide' => 'long',
            'positionSide' => 'LONG',
            'total' => '0.1',
            'size' => 0.1,
            'positionAmt' => 0.1,
            'breakEvenPrice' => '60000',
        ],
    ]);
    Http::fake(['*' => Http::response(['code' => '00000', 'data' => null])]);

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue();
    $result = $job->computeApiable();

    expect($result['new_quantity'])->toBe('0.1')
        ->and((string) $profitOrder->fresh()->quantity)->toContain('0.1')
        ->and((string) $stopOrder->fresh()->price)->toContain('57000');

    Http::assertSent(function (Request $request): bool {
        $body = bitgetUnifiedBody($request);

        return str_contains($request->url(), '/api/v3/trade/modify-strategy-order')
            && $body['orderId'] === 'UTA-WAP-PROFIT-1'
            && $body['qty'] === '0.1'
            && array_key_exists('takeProfit', $body)
            && ! array_key_exists('stopLoss', $body);
    });
    Http::assertSentCount(1);
});
