<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Recovery\AccountRecoveryRunner;
use Kraite\Core\Support\Recovery\RecoveryReport;

function recoverySafetyPosition(string $token, string $direction = 'LONG'): Position
{
    $apiSystem = ApiSystem::query()->firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true],
    );
    $account = Account::query()->firstWhere('api_system_id', $apiSystem->id)
        ?? Account::factory()->create([
            'api_system_id' => $apiSystem->id,
            'binance_api_key' => 'TESTKEY',
            'binance_api_secret' => 'TESTSECRET',
            'is_active' => true,
        ]);
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => $token,
        'quote' => 'USDT',
    ]);

    return Position::factory()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'direction' => $direction,
        'status' => 'active',
        'quantity' => '10',
        'opening_price' => '1',
    ]);
}

function recoverySafetyOpeningOrder(Position $position): Order
{
    return Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => '998877',
        'type' => 'LIMIT',
        'side' => $position->direction === 'LONG' ? 'BUY' : 'SELL',
        'position_side' => $position->direction,
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0.9',
        'quantity' => '10',
        'reference_price' => '0.9',
        'reference_quantity' => '10',
        'is_algo' => false,
    ]));
}

/**
 * @param  Closure(Request): mixed  $cancelResponse
 */
function fakeRecoverySafetyExchange(string $liveSymbol, Closure $cancelResponse): void
{
    Http::fake([
        '*/fapi/v1/order*' => $cancelResponse,
        '*/fapi/v3/positionRisk*' => Http::response([[
            'symbol' => $liveSymbol,
            'positionAmt' => '-5',
            'positionSide' => 'SHORT',
            'entryPrice' => '1',
            'breakEvenPrice' => '1',
            'leverage' => '20',
            'markPrice' => '1',
            'unRealizedProfit' => '0',
            'liquidationPrice' => '0',
            'updateTime' => 1_700_000_000_000,
        ]]),
        '*/fapi/v3/balance*' => Http::response([[
            'asset' => 'USDT',
            'balance' => '1000',
            'crossWalletBalance' => '1000',
        ]]),
        '*/fapi/v1/symbolConfig*' => Http::response([]),
        '*/fapi/v1/openOrders*' => Http::response([]),
        '*/fapi/v1/openAlgoOrders*' => Http::response([]),
        '*/fapi/v1/userTrades*' => Http::response([]),
        '*/fapi/v1/allOrders*' => Http::response([]),
        '*' => Http::response([]),
    ]);
}

function successfulRecoveryCancelResponse(Request $request)
{
    return Http::response([
        'orderId' => '998877',
        'symbol' => 'PHANTOMUSDT',
        'status' => 'CANCELED',
        'price' => '0.9',
        'origQty' => '10',
        'executedQty' => '0',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'origType' => 'LIMIT',
    ]);
}

it('synchronously cancels opening orders before recovery marks a confirmed-flat position closed', function (): void {
    $phantom = recoverySafetyPosition('PHANTOM');
    $order = recoverySafetyOpeningOrder($phantom);
    $liveSibling = recoverySafetyPosition('LIVESIBLING', 'SHORT');
    $statusAtCancel = null;

    fakeRecoverySafetyExchange('LIVESIBLINGUSDT', function (Request $request) use ($phantom, &$statusAtCancel) {
        $statusAtCancel = Position::findOrFail($phantom->id)->status;

        return successfulRecoveryCancelResponse($request);
    });

    (new AccountRecoveryRunner($phantom->account, new RecoveryReport))->run();

    expect($statusAtCancel)->toBe('active')
        ->and($phantom->refresh()->status)->toBe('closed')
        ->and($order->refresh()->status)->toBe('CANCELLED')
        ->and($liveSibling->refresh()->status)->toBe('active');
});

it('preserves the position when it reappears in the confirmation snapshot', function (): void {
    $phantom = recoverySafetyPosition('REAPPEAR');
    $order = recoverySafetyOpeningOrder($phantom);
    recoverySafetyPosition('STEADY', 'SHORT');
    $siblingRow = [
        'symbol' => 'STEADYUSDT',
        'positionAmt' => '-5',
        'positionSide' => 'SHORT',
        'entryPrice' => '1',
        'breakEvenPrice' => '1',
        'leverage' => '20',
        'markPrice' => '1',
        'unRealizedProfit' => '0',
        'liquidationPrice' => '0',
        'updateTime' => 1_700_000_000_000,
    ];
    $cancelCalled = false;

    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::sequence()
            ->push([$siblingRow])
            ->push([$siblingRow])
            ->push([$siblingRow, [
                'symbol' => 'REAPPEARUSDT',
                'positionAmt' => '10',
                'positionSide' => 'LONG',
                'entryPrice' => '1',
                'breakEvenPrice' => '1',
                'leverage' => '20',
                'markPrice' => '1',
                'unRealizedProfit' => '0',
                'liquidationPrice' => '0',
                'updateTime' => 1_700_000_000_000,
            ]]),
        '*/fapi/v1/order*' => function (Request $request) use (&$cancelCalled) {
            $cancelCalled = $cancelCalled || $request->method() === 'DELETE';

            return Http::response([]);
        },
        '*/fapi/v3/balance*' => Http::response([[
            'asset' => 'USDT',
            'balance' => '1000',
            'crossWalletBalance' => '1000',
        ]]),
        '*' => Http::response([]),
    ]);

    (new AccountRecoveryRunner($phantom->account, new RecoveryReport))->run();

    expect($cancelCalled)->toBeFalse()
        ->and($phantom->refresh()->status)->toBe('active')
        ->and($order->refresh()->status)->toBe('NEW');
});

it('cancels owned opening orders before override deletes their ownership rows', function (): void {
    $position = recoverySafetyPosition('OVERRIDE');
    $order = recoverySafetyOpeningOrder($position);
    $statusAtCancel = null;

    fakeRecoverySafetyExchange('UNUSEDUSDT', function (Request $request) use ($position, &$statusAtCancel) {
        $statusAtCancel = Position::findOrFail($position->id)->status;

        return successfulRecoveryCancelResponse($request);
    });

    $command = app(RecoverPositionsCommand::class);
    $method = new ReflectionMethod($command, 'wipeMatchingState');
    $method->invoke($command, collect([$position->account]), null, new RecoveryReport, false);

    expect($statusAtCancel)->toBe('active')
        ->and(Position::find($position->id))->toBeNull()
        ->and(Order::find($order->id))->toBeNull();
});

it('preserves override ownership rows when synchronous cancellation fails', function (): void {
    $position = recoverySafetyPosition('OVERRIDEFAIL');
    $order = recoverySafetyOpeningOrder($position);

    fakeRecoverySafetyExchange('UNUSEDUSDT', fn () => Http::response([
        'code' => -1000,
        'msg' => 'exchange unavailable',
    ], 500));

    $command = app(RecoverPositionsCommand::class);
    $method = new ReflectionMethod($command, 'wipeMatchingState');

    expect(fn () => $method->invoke(
        $command,
        collect([$position->account]),
        null,
        new RecoveryReport,
        false,
    ))->toThrow(RequestException::class)
        ->and(Position::find($position->id))->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull()
        ->and(Order::find($order->id)->reference_status)->toBe('NEW');
});

it('does not make exchange cancellations while previewing an override dry-run', function (): void {
    $position = recoverySafetyPosition('DRYRUN');
    recoverySafetyOpeningOrder($position);
    $cancelCalled = false;

    Http::fake(function () use (&$cancelCalled) {
        $cancelCalled = true;

        return Http::response([]);
    });

    DB::beginTransaction();

    try {
        $command = app(RecoverPositionsCommand::class);
        $method = new ReflectionMethod($command, 'wipeMatchingState');
        $method->invoke($command, collect([$position->account]), null, new RecoveryReport, true);
    } finally {
        DB::rollBack();
    }

    expect($cancelCalled)->toBeFalse()
        ->and(Position::find($position->id))->not->toBeNull();
});
