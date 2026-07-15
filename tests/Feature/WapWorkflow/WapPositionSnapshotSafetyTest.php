<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Position\ConfirmPositionFlatAndCancelOpeningOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

function wapSafetyPosition(string $canonical, string $direction = 'LONG'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => mb_ucfirst($canonical),
    ]);
    $symbol = Symbol::factory()->create(['token' => 'WAPSAFE']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => 'WAPSAFE',
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => false,
    ]);
    $position = Position::factory()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'WAPSAFEUSDT',
        'direction' => $direction,
        'status' => 'waping',
    ]);

    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'WAPSAFE-LIMIT-1',
        'type' => 'LIMIT',
        'side' => $direction === 'LONG' ? 'BUY' : 'SELL',
        'position_side' => $direction,
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1',
        'quantity' => '10',
        'reference_price' => '1',
        'reference_quantity' => '10',
        'is_algo' => false,
    ]));

    return $position;
}

function resolveWapSafetySnapshot(Position $position): ?array
{
    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $method = new ReflectionMethod($job, 'resolvePositionFromSnapshot');
    $method->setAccessible(true);

    return Steps::usingPrefix('trading', fn () => $method->invoke($job));
}

it('finds directional one-way snapshots from exchanges that do not use a BOTH key', function (
    string $canonical,
    array $snapshot,
): void {
    $position = wapSafetyPosition($canonical);
    ApiSnapshot::storeFor($position->account, 'account-positions', $snapshot);

    $exchangePosition = resolveWapSafetySnapshot($position);

    expect($exchangePosition)->not->toBeNull()
        ->and($exchangePosition['breakEvenPrice'])->toBe('100');
})->with([
    'Bybit' => [
        'bybit',
        ['WAPSAFEUSDT:LONG' => [
            'symbol' => 'WAPSAFEUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => '10',
            'breakEvenPrice' => '100',
        ]],
    ],
    'KuCoin' => [
        'kucoin',
        ['WAPSAFEUSDT:LONG' => [
            'symbol' => 'WAPSAFEUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => '10',
            'breakEvenPrice' => '100',
        ]],
    ],
]);

it('treats an opposite hedge side as absent and schedules confirmation', function (): void {
    $position = wapSafetyPosition('binance', 'LONG');
    ApiSnapshot::storeFor($position->account, 'account-positions', [
        'WAPSAFEUSDT:SHORT' => [
            'symbol' => 'WAPSAFEUSDT',
            'positionSide' => 'SHORT',
            'positionAmt' => '-10',
            'breakEvenPrice' => '100',
        ],
    ]);

    $exchangePosition = resolveWapSafetySnapshot($position);
    $confirmations = Steps::usingPrefix('trading', fn () => Step::query()
        ->forRelatable($position)
        ->forClasses(ConfirmPositionFlatAndCancelOpeningOrdersJob::class)
        ->get());

    expect($exchangePosition)->toBeNull()
        ->and($confirmations)->toHaveCount(1)
        ->and($position->refresh()->status)->toBe('waping');
});

it('schedules confirmation instead of cancelling from the first valid empty WAP snapshot', function (): void {
    $position = wapSafetyPosition('binance');
    ApiSnapshot::storeFor($position->account, 'account-positions', []);

    $exchangePosition = resolveWapSafetySnapshot($position);

    expect($exchangePosition)->toBeNull()
        ->and(Steps::usingPrefix('trading', fn (): int => Step::query()
            ->forRelatable($position)
            ->forClasses(ConfirmPositionFlatAndCancelOpeningOrdersJob::class)
            ->count()))->toBe(1);
});
