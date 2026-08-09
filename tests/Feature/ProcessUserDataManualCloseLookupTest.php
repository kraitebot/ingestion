<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\UserDataStream\ProcessUserDataEventJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

/** @return array{account: Account, apiSystem: ApiSystem, position: Position} */
function manualCloseStreamFixture(
    string $token,
    string $direction = 'LONG',
    bool $hedgeMode = false,
    string $status = 'active',
): array {
    config()->set('kraite.user_data_stream.binance.dispatched_executions', ['TRADE', 'ALGO_FILLED']);

    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => $token,
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => $hedgeMode,
        'binance_api_key' => 'stream-key',
        'binance_api_secret' => 'stream-secret',
    ]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => $status,
        'direction' => $direction,
    ]);

    return compact('account', 'apiSystem', 'position');
}

/** @return array<string, mixed> */
function manualCloseStreamPayload(
    string $symbol,
    string $side = 'SELL',
    string $positionSide = 'BOTH',
    bool $reduceOnly = true,
    string $status = 'FILLED',
    string $executionType = 'TRADE',
    string $exchangeOrderId = 'external-123',
    string $clientOrderId = 'external-close',
): array {
    return [
        'e' => 'ORDER_TRADE_UPDATE',
        'E' => 1_780_000_000_000,
        'o' => [
            's' => $symbol,
            'c' => $clientOrderId,
            'i' => $exchangeOrderId,
            'S' => $side,
            'ps' => $positionSide,
            'o' => 'MARKET',
            'X' => $status,
            'x' => $executionType,
            'q' => '1',
            'z' => '1',
            'l' => '1',
            'p' => '0',
            'ap' => '1.25',
            'L' => '1.25',
            'R' => $reduceOnly,
        ],
    ];
}

/** @param array{account: Account, apiSystem: ApiSystem, position: Position} $fixture */
function processManualCloseStream(array $fixture, array $payload): array
{
    return (new ProcessUserDataEventJob(
        accountId: $fixture['account']->id,
        apiSystemId: $fixture['apiSystem']->id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();
}

function attachManualCloseStreamOrder(
    Position $position,
    ?string $exchangeOrderId,
    string $clientOrderId,
    bool $isAlgo = false,
): Order {
    return Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => $clientOrderId,
        'exchange_order_id' => $exchangeOrderId,
        'type' => $isAlgo ? 'STOP-MARKET' : 'MARKET-CANCEL',
        'side' => $position->direction === 'LONG' ? 'SELL' : 'BUY',
        'position_side' => $position->direction,
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.25',
        'quantity' => $isAlgo ? '0' : '1',
        'reference_price' => '1.25',
        'reference_quantity' => $isAlgo ? '0' : '1',
        'is_algo' => $isAlgo,
    ]));
}

it('finds a one-way active position by its stored trading pair for an unowned reduce-only fill', function (): void {
    $fixture = manualCloseStreamFixture('MANUAL');

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload('MANUALUSDT'),
    );
    $replacement = Step::query()->where('priority', 'high')->sole();

    expect($result['manual_close_detected'])->toBeTrue()
        ->and($replacement->arguments['manualCloseDetected'])->toBeTrue()
        ->and($replacement->arguments['manualClosingPrice'])->toBe('1.25')
        ->and($fixture['position']->refresh()->manually_closed_at)->toBeNull();
});

it('detects a hedge-mode close from side and positionSide without reduceOnly', function (): void {
    $fixture = manualCloseStreamFixture('HEDGELONG', hedgeMode: true);

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload('HEDGELONGUSDT', positionSide: 'LONG', reduceOnly: false),
    );

    expect($result['manual_close_detected'])->toBeTrue()
        ->and(Step::query()
            ->where('relatable_id', $fixture['position']->id)
            ->whereJsonContains('arguments->manualCloseDetected', true)
            ->sole()->arguments['manualCloseDetected'])
        ->toBeTrue();
});

it('rejects hedge frames that do not close the exact local side', function (string $side, string $positionSide): void {
    $fixture = manualCloseStreamFixture('HEDGEWRONG', hedgeMode: true);

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload(
            'HEDGEWRONGUSDT',
            side: $side,
            positionSide: $positionSide,
            reduceOnly: false,
        ),
    );

    expect($result['manual_close_detected'])->toBeFalse()
        ->and(Step::query()
            ->where('relatable_id', $fixture['position']->id)
            ->whereJsonContains('arguments->manualCloseDetected', true)
            ->exists())->toBeFalse();
})->with([
    'opening side' => ['BUY', 'LONG'],
    'opposite hedge side' => ['SELL', 'SHORT'],
    'one-way tag on hedge account' => ['SELL', 'BOTH'],
]);

it('never detects liquidation or ADL execution frames as manual', function (string $clientOrderId): void {
    $fixture = manualCloseStreamFixture('FORCED');

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload(
            'FORCEDUSDT',
            executionType: 'CALCULATED',
            clientOrderId: $clientOrderId,
        ),
    );

    expect($result['manual_close_detected'])->toBeFalse();
})->with(['autoclose-123', 'adl_autoclose']);

it('never detects a partial reducing fill as a flat manual close', function (): void {
    $fixture = manualCloseStreamFixture('PARTIAL');

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload('PARTIALUSDT', status: 'PARTIALLY_FILLED'),
    );

    expect($result['manual_close_detected'])->toBeFalse();
});

it('keeps a Kraite order automatic when matched by exchange or client id', function (
    ?string $storedExchangeOrderId,
    string $storedClientOrderId,
    string $eventExchangeOrderId,
    string $eventClientOrderId,
): void {
    $fixture = manualCloseStreamFixture('OWNED');
    attachManualCloseStreamOrder($fixture['position'], $storedExchangeOrderId, $storedClientOrderId);

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload(
            'OWNEDUSDT',
            exchangeOrderId: $eventExchangeOrderId,
            clientOrderId: $eventClientOrderId,
        ),
    );

    expect($result['manual_close_detected'])->toBeFalse()
        ->and(Step::query()->whereJsonContains('arguments->manualCloseDetected', true)->count())->toBe(0);
})->with([
    'exchange id' => ['kraite-order', 'kraite-client', 'kraite-order', 'different-client'],
    'placement race client id' => [null, 'kraite-client', 'new-exchange-id', 'kraite-client'],
]);

it('detects a closePosition algo for the exact hedge side', function (): void {
    $fixture = manualCloseStreamFixture('ALGOEXT', direction: 'SHORT', hedgeMode: true);
    $payload = [
        'e' => 'ALGO_UPDATE',
        'E' => 1_780_000_000_000,
        'o' => [
            's' => 'ALGOEXTUSDT',
            'aid' => 'external-algo',
            'caid' => 'external-algo-client',
            'S' => 'BUY',
            'ps' => 'SHORT',
            'ot' => 'STOP_MARKET',
            'X' => 'FILLED',
            'cp' => true,
        ],
    ];

    $result = processManualCloseStream($fixture, $payload);

    expect($result['manual_close_detected'])->toBeTrue();
});

it('detects closes while the position is in every active lifecycle status', function (string $status): void {
    $fixture = manualCloseStreamFixture("STATUS{$status}", status: $status);

    $result = processManualCloseStream(
        $fixture,
        manualCloseStreamPayload("STATUS{$status}USDT"),
    );

    expect($result['manual_close_detected'])->toBeTrue();
})->with(['opening', 'active', 'new', 'waping', 'syncing']);

it('does not dispatch duplicate manual-close workflows', function (): void {
    $fixture = manualCloseStreamFixture('DEDUPE');

    $first = processManualCloseStream($fixture, manualCloseStreamPayload('DEDUPEUSDT'));
    $secondPayload = manualCloseStreamPayload('DEDUPEUSDT', exchangeOrderId: 'external-456');
    $secondPayload['E']++;
    $second = processManualCloseStream($fixture, $secondPayload);

    expect($first['manual_close_detected'])->toBeTrue()
        ->and($second['manual_close_detected'])->toBeFalse()
        ->and(Step::query()
            ->where('relatable_id', $fixture['position']->id)
            ->whereJsonContains('arguments->manualCloseDetected', true)
            ->count())->toBe(1);
});
