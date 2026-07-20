<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\SyncLeverageBracketJob;
use Kraite\Core\Jobs\Atomic\Position\Bitget\FetchAccountPositionsPnlJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Recovery\BitgetPositionRecoverer;
use Kraite\Core\Support\Recovery\RecoveryReport;

/** @return array{account: Account, apiSystem: ApiSystem, exchangeSymbol: ExchangeSymbol} */
function bitgetUsdcExecutionFixture(): array
{
    Kraite::query()->findOrFail(1)->update([
        'bitget_api_key' => 'USDC_ADMIN_KEY',
        'bitget_api_secret' => 'USDC_ADMIN_SECRET',
        'bitget_passphrase' => 'USDC_ADMIN_PASSPHRASE',
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget USDC Execution Test',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'BTC']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'asset' => 'BTCPERP',
        'token' => 'BTC',
        'quote' => 'USDC',
        'price_precision' => 1,
        'quantity_precision' => 4,
    ]);
    $account = Account::factory()->oneWayMode()->create([
        'api_system_id' => $apiSystem->id,
        'portfolio_quote' => 'USDC',
        'trading_quote' => 'USDC',
        'margin_mode' => 'isolated',
        'bitget_api_key' => 'USDC_ACCOUNT_KEY',
        'bitget_api_secret' => 'USDC_ACCOUNT_SECRET',
        'bitget_passphrase' => 'USDC_ACCOUNT_PASSPHRASE',
        // Steady state: mode already detected. A null mode adds a one-time
        // v2 probe request, which would skew the exact request-count asserts.
        'bitget_account_mode' => 'classic',
    ]);

    return compact('account', 'apiSystem', 'exchangeSymbol');
}

function bitgetRequestProductType(Request $request): ?string
{
    $productType = $request['productType'];

    return is_string($productType) ? $productType : null;
}

it('sends the exchange symbol quote through the real leverage bracket job', function (): void {
    ['exchangeSymbol' => $exchangeSymbol] = bitgetUsdcExecutionFixture();

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'msg' => 'success',
            'data' => [[
                'symbol' => 'BTCPERP',
                'level' => '1',
                'startUnit' => '0',
                'endUnit' => '50000',
                'leverage' => '125',
                'keepMarginRate' => '0.004',
            ]],
        ]),
    ]);

    $job = new SyncLeverageBracketJob($exchangeSymbol->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result['status'])->toBe('updated')
        ->and($exchangeSymbol->fresh()->leverage_brackets[0]['initialLeverage'])->toBe(125);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/query-position-lever')
        && bitgetRequestProductType($request) === 'USDC-FUTURES');
});

it('uses USDC futures for flash close and closing price lookup', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol] = bitgetUsdcExecutionFixture();
    $position = Position::factory()->long()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'BTCPERP',
        'direction' => 'LONG',
        'closing_price' => null,
        'total_limit_orders' => 4,
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/close-positions')) {
            return Http::response([
                'code' => '00000',
                'data' => ['successList' => [['orderId' => 'USDC-CLOSE-1']], 'failureList' => []],
            ]);
        }

        return Http::response([
            'code' => '00000',
            'data' => ['orderId' => 'USDC-CLOSE-1', 'priceAvg' => '61234.5'],
        ]);
    });

    $response = $position->apiCloseBitget();

    expect($response->result['success'])->toBeTrue()
        ->and((string) $position->fresh()->closing_price)->toContain('61234.5');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/close-positions')
        && bitgetRequestProductType($request) === 'USDC-FUTURES');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/order/detail')
        && bitgetRequestProductType($request) === 'USDC-FUTURES');
});

it('uses the account trading quote for historical position PnL', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol] = bitgetUsdcExecutionFixture();
    $openedAt = now()->subHours(2);
    $closedAt = now()->subHour();
    $position = Position::factory()->long()->closed()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'BTCPERP',
        'direction' => 'LONG',
        'opened_at' => $openedAt,
        'closed_at' => $closedAt,
        'pnl' => null,
    ]);

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'data' => ['list' => [[
                'symbol' => 'BTCPERP',
                'holdSide' => 'long',
                'ctime' => (string) $openedAt->getTimestampMs(),
                'utime' => (string) $closedAt->getTimestampMs(),
                'netProfit' => '12.34',
            ]]],
        ]),
    ]);

    $result = (new FetchAccountPositionsPnlJob($account->id))->computeApiable();

    expect($result['updated'])->toBe(1)
        ->and((string) $position->fresh()->pnl)->toContain('12.34');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/history-position')
        && bitgetRequestProductType($request) === 'USDC-FUTURES');
});

it('recovers BTCPERP with USDC context across positions orders and fills', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol] = bitgetUsdcExecutionFixture();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/all-position')) {
            return Http::response([
                'code' => '00000',
                'data' => [[
                    'symbol' => 'BTCPERP',
                    'marginCoin' => 'USDC',
                    'holdSide' => 'long',
                    'total' => '0.01',
                    'leverage' => '10',
                    'openPriceAvg' => '60000',
                    'breakEvenPrice' => '60010',
                    'posMode' => 'one_way_mode',
                    'cTime' => (string) now()->subHour()->getTimestampMs(),
                ]],
            ]);
        }

        if (str_contains($request->url(), '/fills')) {
            return Http::response(['code' => '00000', 'data' => ['fillList' => []]]);
        }

        return Http::response(['code' => '00000', 'data' => ['entrustedList' => []]]);
    });

    $report = new RecoveryReport;
    (new BitgetPositionRecoverer($account, $report))->run();

    $position = Position::query()->where('account_id', $account->id)->sole();

    expect($position->exchange_symbol_id)->toBe($exchangeSymbol->id)
        ->and($position->parsed_trading_pair)->toBe('BTCPERP')
        ->and($report->positionsCreated)->toBe(1)
        ->and($report->positionsSkipped)->toBe(0)
        ->and($report->warnings)->toBe([]);

    $contextualEndpoints = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => collect([
            '/all-position',
            '/orders-pending',
            '/orders-plan-pending',
            '/fills',
        ])->contains(fn (string $path): bool => str_contains($request->url(), $path)));

    expect($contextualEndpoints)->toHaveCount(4)
        ->and($contextualEndpoints->every(
            fn (Request $request): bool => bitgetRequestProductType($request) === 'USDC-FUTURES'
        ))->toBeTrue();
});

it('selects only Bitget USDC contracts for a Bitget USDC account', function (): void {
    ['account' => $account, 'exchangeSymbol' => $bitgetUsdc] = bitgetUsdcExecutionFixture();
    Kraite::query()->findOrFail(1)->update(['td_correlation_type' => 'rolling']);

    $eligible = [
        'api_statuses' => ['has_taapi_data' => true, 'taapi_verified' => true],
        'direction' => 'LONG',
        'indicators_timeframe' => '1h',
        'btc_correlation_rolling' => ['1h' => 0.5],
        'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 50]],
        'is_manually_enabled' => true,
        'is_marked_for_delisting' => false,
        'has_no_indicator_data' => false,
        'was_backtesting_approved' => true,
    ];
    $bitgetUsdc->update($eligible);

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance USDC Counterpart',
    ]);
    ExchangeSymbol::factory()->create([
        ...$eligible,
        'api_system_id' => $binance->id,
        'symbol_id' => $bitgetUsdc->symbol_id,
        'asset' => 'BTCUSDC',
        'token' => 'BTC',
        'quote' => 'USDC',
    ]);
    ExchangeSymbol::factory()->create([
        ...$eligible,
        'api_system_id' => $bitgetUsdc->api_system_id,
        'symbol_id' => $bitgetUsdc->symbol_id,
        'asset' => 'BTCUSDT',
        'token' => 'BTC',
        'quote' => 'USDT',
    ]);

    $available = $account->availableExchangeSymbols();

    expect($available)->toHaveCount(1)
        ->and($available->sole()->id)->toBe($bitgetUsdc->id)
        ->and($available->sole()->asset)->toBe('BTCPERP')
        ->and($available->sole()->quote)->toBe('USDC');
});

it('runs a complete USDC configure entry protection sync cancel and close API lifecycle', function (): void {
    ['account' => $account, 'exchangeSymbol' => $exchangeSymbol] = bitgetUsdcExecutionFixture();
    $position = Position::factory()->long()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'BTCPERP',
        'direction' => 'LONG',
        'closing_price' => null,
        'total_limit_orders' => 4,
    ]);
    $entry = Order::create([
        'position_id' => $position->id,
        'client_order_id' => 'USDC-ENTRY-CLIENT',
        'side' => 'BUY',
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '60000.0',
        'quantity' => '0.0100',
        'position_side' => 'LONG',
        'is_algo' => false,
    ]);
    $protection = Order::create([
        'position_id' => $position->id,
        'client_order_id' => 'USDC-STOP-CLIENT',
        'side' => 'SELL',
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'price' => '57000.0',
        'quantity' => '0.0100',
        'position_side' => 'LONG',
        'is_algo' => true,
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/set-margin-mode') => Http::response([
                'code' => '00000',
                'data' => ['symbol' => 'BTCPERP', 'marginCoin' => 'USDC', 'marginMode' => 'isolated'],
            ]),
            str_contains($url, '/set-leverage') => Http::response([
                'code' => '00000',
                'data' => ['symbol' => 'BTCPERP', 'marginCoin' => 'USDC', 'longLeverage' => '10'],
            ]),
            str_contains($url, '/place-order') => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'USDC-ENTRY-1', 'clientOid' => 'USDC-ENTRY-CLIENT'],
            ]),
            str_contains($url, '/modify-order') => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'USDC-ENTRY-1', 'clientOid' => 'USDC-ENTRY-MODIFIED'],
            ]),
            str_contains($url, '/cancel-order') => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'USDC-ENTRY-1', 'clientOid' => 'USDC-ENTRY-MODIFIED'],
            ]),
            str_contains($url, '/place-pos-tpsl') => Http::response([
                'code' => '00000',
                'data' => ['symbol' => 'BTCPERP'],
            ]),
            str_contains($url, '/all-position') => Http::response([
                'code' => '00000',
                'data' => [[
                    'symbol' => 'BTCPERP',
                    'marginCoin' => 'USDC',
                    'holdSide' => 'long',
                    'total' => '0.0100',
                    'posMode' => 'one_way_mode',
                    'stopLossId' => 'USDC-STOP-1',
                    'takeProfitId' => null,
                ]],
            ]),
            str_contains($url, '/orders-plan-pending') => Http::response([
                'code' => '00000',
                'data' => ['entrustedList' => [[
                    'orderId' => 'USDC-STOP-1',
                    'symbol' => 'BTCPERP',
                    'planType' => 'pos_loss',
                    'planStatus' => 'live',
                    'triggerPrice' => '57000.0',
                    'size' => '0.0100',
                    'side' => 'sell',
                ]]],
            ]),
            str_contains($url, '/cancel-plan-order') => Http::response([
                'code' => '00000',
                'data' => [
                    'successList' => [['orderId' => 'USDC-STOP-1', 'clientOid' => 'USDC-STOP-CLIENT']],
                    'failureList' => [],
                ],
            ]),
            str_contains($url, '/close-positions') => Http::response([
                'code' => '00000',
                'data' => ['successList' => [['orderId' => 'USDC-CLOSE-2']], 'failureList' => []],
            ]),
            str_contains($url, '/order/detail') && $request['orderId'] === 'USDC-CLOSE-2' => Http::response([
                'code' => '00000',
                'data' => ['orderId' => 'USDC-CLOSE-2', 'symbol' => 'BTCPERP', 'priceAvg' => '61234.5'],
            ]),
            str_contains($url, '/order/detail') => Http::response([
                'code' => '00000',
                'data' => [
                    'orderId' => 'USDC-ENTRY-1',
                    'symbol' => 'BTCPERP',
                    'state' => 'new',
                    'orderType' => 'limit',
                    'price' => '60000.0',
                    'priceAvg' => '0',
                    'size' => '0.0100',
                    'filledQty' => '0',
                    'side' => 'buy',
                ],
            ]),
            default => Http::response(['code' => '00000', 'data' => []]),
        };
    });

    expect($position->apiUpdateMarginType()->result['marginCoin'])->toBe('USDC')
        ->and($position->apiUpdateLeverageRatio(10)->result['longLeverage'])->toBe('10')
        ->and($entry->apiPlace()->result['orderId'])->toBe('USDC-ENTRY-1')
        ->and($entry->apiQuery()->result['status'])->toBe('NEW')
        ->and($entry->apiModify('0.0200', '59000.0')->result['order_id'])->toBe('USDC-ENTRY-1')
        ->and($entry->apiCancel()->result['status'])->toBe('CANCELLED')
        ->and($protection->apiPlace()->result['orderId'])->toBe('USDC-STOP-1')
        ->and($protection->apiQueryPlanOrder()->result['status'])->toBe('NEW')
        ->and($protection->apiCancelPlanOrder()->result['status'])->toBe('CANCELLED')
        ->and($position->apiCloseBitget()->result['success'])->toBeTrue()
        ->and((string) $position->fresh()->closing_price)->toContain('61234.5');

    $requests = collect(Http::recorded())->map(fn (array $record): Request => $record[0]);
    $symbolRequests = $requests->reject(
        fn (Request $request): bool => str_contains($request->url(), '/all-position')
    );

    expect($requests)->toHaveCount(12)
        ->and($symbolRequests)->toHaveCount(11)
        ->and($symbolRequests->every(fn (Request $request): bool => $request['symbol'] === 'BTCPERP'))
        ->toBeTrue()
        ->and($requests->every(fn (Request $request): bool => $request['productType'] === 'USDC-FUTURES'))
        ->toBeTrue();

    $marginCoinEndpoints = [
        '/set-margin-mode',
        '/set-leverage',
        '/place-order',
        '/modify-order',
        '/cancel-order',
        '/place-pos-tpsl',
        '/cancel-plan-order',
    ];
    $marginRequests = $requests->filter(
        fn (Request $request): bool => collect($marginCoinEndpoints)
            ->contains(fn (string $path): bool => str_contains($request->url(), $path))
    );

    expect($marginRequests)->toHaveCount(7)
        ->and($marginRequests->every(fn (Request $request): bool => $request['marginCoin'] === 'USDC'))
        ->toBeTrue();
});

it('uses USDC context in orphan cancellation and close recovery paths', function (): void {
    ['account' => $account] = bitgetUsdcExecutionFixture();

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'data' => ['successList' => [['orderId' => 'USDC-ORPHAN-CLOSE']], 'failureList' => []],
        ]),
    ]);

    $command = app(CheckSystemHealthCommand::class);
    $cancel = new ReflectionMethod($command, 'cancelOrphanOrder');
    $close = new ReflectionMethod($command, 'closeOrphanPosition');

    $cancel->invoke($command, $account, 'USDC-REGULAR-ORPHAN', 'BTCPERP', false);
    $cancel->invoke($command, $account, 'USDC-PLAN-ORPHAN', 'BTCPERP', true);
    $close->invoke($command, $account, 'BTCPERP', 'LONG', '0.01');

    $requests = collect(Http::recorded())->map(fn (array $record): Request => $record[0]);

    expect($requests)->toHaveCount(3)
        ->and($requests->contains(fn (Request $request): bool => str_contains($request->url(), '/cancel-order')))
        ->toBeTrue()
        ->and($requests->contains(fn (Request $request): bool => str_contains($request->url(), '/cancel-plan-order')))
        ->toBeTrue()
        ->and($requests->contains(fn (Request $request): bool => str_contains($request->url(), '/close-positions')))
        ->toBeTrue()
        ->and($requests->every(fn (Request $request): bool => $request['symbol'] === 'BTCPERP'))
        ->toBeTrue()
        ->and($requests->every(fn (Request $request): bool => $request['productType'] === 'USDC-FUTURES'))
        ->toBeTrue();

    $cancelRequests = $requests->filter(
        fn (Request $request): bool => str_contains($request->url(), '/cancel-')
    );

    expect($cancelRequests->every(fn (Request $request): bool => $request['marginCoin'] === 'USDC'))
        ->toBeTrue();

    $planCancel = $requests->sole(
        fn (Request $request): bool => str_contains($request->url(), '/cancel-plan-order')
    );

    expect($planCancel['orderIdList'])->toBe([[
        'orderId' => 'USDC-PLAN-ORPHAN',
        'clientOid' => '',
    ]]);
});
