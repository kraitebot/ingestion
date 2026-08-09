<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Enums\PositionCloseAttribution;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiDataStream;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\BinancePositionCloseEvidenceClassifier;
use Kraite\Core\Support\PositionManualCloseAttributor;

function attributionPosition(string $token, string $direction = 'LONG', bool $hedgeMode = false): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => "Binance {$token}",
    ]);
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => $token,
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'name' => "Account {$token}",
        'on_hedge_mode' => $hedgeMode,
        'binance_api_key' => "key-{$token}",
        'binance_api_secret' => "secret-{$token}",
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'uuid' => Str::uuid()->toString(),
        'parsed_trading_pair' => "{$token}USDT",
        'status' => 'active',
        'direction' => $direction,
        'opened_at' => now()->subHour(),
    ]);
}

/** @return array<string, mixed> */
function attributionTrade(string $orderId, string $side, string $positionSide, int $time, int $id = 1): array
{
    return [
        'id' => $id,
        'orderId' => $orderId,
        'side' => $side,
        'positionSide' => $positionSide,
        'price' => '0.09500660',
        'qty' => '10',
        'time' => $time,
    ];
}

/** @return array<string, mixed> */
function attributionOrder(
    string $orderId,
    string $side,
    string $positionSide,
    bool $reduceOnly,
    string $clientOrderId = 'ios_manual_close',
): array {
    return [
        'orderId' => $orderId,
        'clientOrderId' => $clientOrderId,
        'status' => 'FILLED',
        'type' => 'MARKET',
        'side' => $side,
        'positionSide' => $positionSide,
        'reduceOnly' => $reduceOnly,
        'closePosition' => false,
        'executedQty' => '10',
        'avgPrice' => '0.09500660',
        'updateTime' => now()->getTimestampMs(),
    ];
}

/** @return array<string, mixed> */
function attributionAlgo(
    string $algoId,
    string $actualOrderId,
    string $side,
    string $positionSide,
    string $clientAlgoId = 'ios_manual_algo',
): array {
    return [
        'algoId' => $algoId,
        'clientAlgoId' => $clientAlgoId,
        'actualOrderId' => $actualOrderId,
        'algoStatus' => 'FINISHED',
        'orderType' => 'STOP_MARKET',
        'side' => $side,
        'positionSide' => $positionSide,
        'closePosition' => true,
        'reduceOnly' => false,
        'actualQty' => '10',
        'actualPrice' => '0.09500660',
        'updateTime' => now()->getTimestampMs(),
    ];
}

function classifyCloseEvidence(
    Position $position,
    array $trades,
    ?array $regularOrder = null,
    array $algoOrders = [],
    array $forceOrders = [],
): PositionCloseAttribution {
    return app(BinancePositionCloseEvidenceClassifier::class)->classify(
        position: $position,
        trades: $trades,
        regularOrder: $regularOrder,
        algoOrders: $algoOrders,
        forceOrders: $forceOrders,
    );
}

/**
 * @param  list<Response|Throwable>  $responses
 * @return object{transactions: array<int, array{request: Request}>}
 */
function mockManualCloseBinance(array $responses): object
{
    $capture = new stdClass;
    $capture->transactions = [];
    $queue = $responses;

    Http::fake(function (Request $request) use ($capture, &$queue) {
        $capture->transactions[] = ['request' => $request];
        $next = array_shift($queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return Http::response(
            body: (string) $next->getBody(),
            status: $next->getStatusCode(),
            headers: $next->getHeaders(),
        );
    });

    return $capture;
}

/** @param array<string, mixed> $payload */
function recordManualCloseEvent(Position $position, array $payload, int $eventTimeMs): ApiDataStream
{
    $order = $payload['o'] ?? [];
    $isAlgo = ($payload['e'] ?? '') === 'ALGO_UPDATE';

    return ApiDataStream::create([
        'account_id' => $position->account_id,
        'api_system_id' => $position->account->api_system_id,
        'raw_event_type' => (string) ($payload['e'] ?? 'ORDER_TRADE_UPDATE'),
        'event_type' => 'order_update',
        'exchange_order_id' => (string) ($isAlgo ? ($order['aid'] ?? '') : ($order['i'] ?? '')),
        'client_order_id' => (string) ($isAlgo ? ($order['caid'] ?? '') : ($order['c'] ?? '')),
        'symbol' => $position->parsed_trading_pair,
        'status' => (string) ($order['X'] ?? 'FILLED'),
        'normalized_status' => 'FILLED',
        'event_time' => now()->createFromTimestampMs($eventTimeMs),
        'received_at' => now(),
        'raw_payload' => $payload,
        'idempotency_key' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);
}

it('attributes an unowned one-way reduce-only close to the user', function (): void {
    $position = attributionPosition('EXTONE');
    $trade = attributionTrade('external-1', 'SELL', 'BOTH', now()->getTimestampMs());
    $order = attributionOrder('external-1', 'SELL', 'BOTH', true);

    expect(classifyCloseEvidence($position, [$trade], $order))
        ->toBe(PositionCloseAttribution::External);
});

it('attributes an unowned hedge close without reduce-only to the user', function (): void {
    $position = attributionPosition('EXTHEDGE', hedgeMode: true);
    $trade = attributionTrade('external-2', 'SELL', 'LONG', now()->getTimestampMs());
    $order = attributionOrder('external-2', 'SELL', 'LONG', false);

    expect(classifyCloseEvidence($position, [$trade], $order))
        ->toBe(PositionCloseAttribution::External);
});

it('leaves a one-way opposite order without a closing flag unknown', function (): void {
    $position = attributionPosition('AMBONE');
    $trade = attributionTrade('ambiguous-1', 'SELL', 'BOTH', now()->getTimestampMs());
    $order = attributionOrder('ambiguous-1', 'SELL', 'BOTH', false);

    expect(classifyCloseEvidence($position, [$trade], $order))
        ->toBe(PositionCloseAttribution::Unknown);
});

it('keeps a Kraite regular close automatic by exchange order id', function (): void {
    $position = attributionPosition('OWNEX');
    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => 'kraite-client-1',
        'exchange_order_id' => 'kraite-order-1',
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '0.1',
        'quantity' => '10',
        'reference_price' => '0.1',
        'reference_quantity' => '10',
        'is_algo' => false,
    ]));
    $trade = attributionTrade('kraite-order-1', 'SELL', 'BOTH', now()->getTimestampMs());
    $order = attributionOrder('kraite-order-1', 'SELL', 'BOTH', true, 'different-client-id');

    expect(classifyCloseEvidence($position, [$trade], $order))
        ->toBe(PositionCloseAttribution::Kraite);
});

it('keeps a placement-race close automatic by client order id', function (): void {
    $position = attributionPosition('OWNCLIENT');
    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => 'kraite-client-race',
        'exchange_order_id' => null,
        'type' => 'MARKET-CANCEL',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0',
        'quantity' => '10',
        'reference_price' => '0',
        'reference_quantity' => '10',
        'is_algo' => false,
    ]));
    $trade = attributionTrade('new-exchange-id', 'SELL', 'BOTH', now()->getTimestampMs());
    $order = attributionOrder('new-exchange-id', 'SELL', 'BOTH', true, 'kraite-client-race');

    expect(classifyCloseEvidence($position, [$trade], $order))
        ->toBe(PositionCloseAttribution::Kraite);
});

it('keeps the generated execution of a Kraite algo stop automatic', function (): void {
    $position = attributionPosition('OWNALGO', hedgeMode: true);
    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => 'kraite-algo-client',
        'exchange_order_id' => 'kraite-algo-id',
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0.09',
        'quantity' => '0',
        'reference_price' => '0.09',
        'reference_quantity' => '0',
        'is_algo' => true,
    ]));
    $trade = attributionTrade('generated-order-id', 'SELL', 'LONG', now()->getTimestampMs());
    $algo = attributionAlgo(
        algoId: 'kraite-algo-id',
        actualOrderId: 'generated-order-id',
        side: 'SELL',
        positionSide: 'LONG',
        clientAlgoId: 'kraite-algo-client',
    );

    expect(classifyCloseEvidence($position, [$trade], null, [$algo]))
        ->toBe(PositionCloseAttribution::Kraite);
});

it('attributes an external close-position algo to the user', function (): void {
    $position = attributionPosition('EXTALGO', hedgeMode: true);
    $trade = attributionTrade('generated-external-id', 'SELL', 'LONG', now()->getTimestampMs());
    $algo = attributionAlgo('external-algo-id', 'generated-external-id', 'SELL', 'LONG');

    expect(classifyCloseEvidence($position, [$trade], null, [$algo]))
        ->toBe(PositionCloseAttribution::External);
});

it('never attributes a liquidation or ADL force order as manual', function (string $autoCloseType): void {
    $position = attributionPosition("FORCE{$autoCloseType}");
    $trade = attributionTrade('force-order-1', 'SELL', 'BOTH', now()->getTimestampMs());
    $order = attributionOrder('force-order-1', 'SELL', 'BOTH', true);
    $forceOrder = [
        'orderId' => 'force-order-1',
        'autoCloseType' => $autoCloseType,
        'status' => 'FILLED',
    ];

    expect(classifyCloseEvidence($position, [$trade], $order, forceOrders: [$forceOrder]))
        ->toBe(PositionCloseAttribution::Forced);
})->with(['LIQUIDATION', 'ADL']);

it('uses the chronologically final closing trade rather than response order', function (): void {
    $position = attributionPosition('FINALTRADE');
    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => 'kraite-final-client',
        'exchange_order_id' => 'kraite-final-order',
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '0.1',
        'quantity' => '10',
        'reference_price' => '0.1',
        'reference_quantity' => '10',
        'is_algo' => false,
    ]));
    $later = attributionTrade('kraite-final-order', 'SELL', 'BOTH', 2_000, 20);
    $earlier = attributionTrade('external-earlier', 'SELL', 'BOTH', 1_000, 10);
    $order = attributionOrder('kraite-final-order', 'SELL', 'BOTH', true, 'kraite-final-client');

    expect(classifyCloseEvidence($position, [$later, $earlier], $order))
        ->toBe(PositionCloseAttribution::Kraite);
});

it('recovers an archived one-way manual close without making REST calls', function (): void {
    $position = attributionPosition('ARCHIVE');
    $eventTimeMs = now()->subSecond()->getTimestampMs();
    recordManualCloseEvent($position, [
        'e' => 'ORDER_TRADE_UPDATE',
        'E' => $eventTimeMs,
        'o' => [
            'i' => 'archived-external-order',
            'c' => 'ios_archived_close',
            'S' => 'SELL',
            'ps' => 'BOTH',
            'X' => 'FILLED',
            'x' => 'TRADE',
            'R' => true,
            'cp' => false,
            'z' => '10',
            'ap' => '0.09500660',
        ],
    ], $eventTimeMs);
    $capture = mockManualCloseBinance([]);

    $result = app(PositionManualCloseAttributor::class)->resolveEvidence(
        $position,
        now()->getTimestampMs(),
    );

    expect($result->attribution)->toBe(PositionCloseAttribution::External)
        ->and($result->closingPrice)->toBe('0.09500660')
        ->and($capture->transactions)->toHaveCount(0)
        ->and($position->refresh()->manually_closed_at)->toBeNull();
});

it('keeps an archived Kraite algo stop automatic', function (): void {
    $position = attributionPosition('ARCHOWN', hedgeMode: true);
    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => 'archived-kraite-algo-client',
        'exchange_order_id' => 'archived-kraite-algo-id',
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0.09',
        'quantity' => '0',
        'reference_price' => '0.09',
        'reference_quantity' => '0',
        'is_algo' => true,
    ]));
    $eventTimeMs = now()->subSecond()->getTimestampMs();
    recordManualCloseEvent($position, [
        'e' => 'ALGO_UPDATE',
        'E' => $eventTimeMs,
        'o' => [
            'aid' => 'archived-kraite-algo-id',
            'caid' => 'archived-kraite-algo-client',
            'S' => 'SELL',
            'ps' => 'LONG',
            'X' => 'FILLED',
            'cp' => true,
            'q' => '0',
            'ap' => '0.09500660',
        ],
    ], $eventTimeMs);
    $capture = mockManualCloseBinance([]);

    $result = app(PositionManualCloseAttributor::class)->resolve($position, now()->getTimestampMs());

    expect($result)->toBe(PositionCloseAttribution::Kraite)
        ->and($capture->transactions)->toHaveCount(0);
});

it('never recovers a CALCULATED liquidation frame as manual', function (): void {
    $position = attributionPosition('ARCHFORCE');
    $eventTimeMs = now()->subSecond()->getTimestampMs();
    recordManualCloseEvent($position, [
        'e' => 'ORDER_TRADE_UPDATE',
        'E' => $eventTimeMs,
        'o' => [
            'i' => 'liquidation-order',
            'c' => 'autoclose-123',
            'S' => 'SELL',
            'ps' => 'BOTH',
            'X' => 'FILLED',
            'x' => 'CALCULATED',
            'R' => true,
            'cp' => false,
        ],
    ], $eventTimeMs);
    $capture = mockManualCloseBinance([]);

    $result = app(PositionManualCloseAttributor::class)->resolve($position, now()->getTimestampMs());

    expect($result)->toBe(PositionCloseAttribution::Forced)
        ->and($capture->transactions)->toHaveCount(0);
});

it('recovers a dropped one-way stream event from bounded Binance history', function (): void {
    $position = attributionPosition('RESTEXT');
    $flatObservedAtMs = now()->getTimestampMs();
    $trade = attributionTrade('rest-external-order', 'SELL', 'BOTH', $flatObservedAtMs - 1_000);
    $order = attributionOrder('rest-external-order', 'SELL', 'BOTH', true);
    $capture = mockManualCloseBinance([
        new Response(200, [], json_encode([$trade], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode($order, JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
    ]);

    $result = app(PositionManualCloseAttributor::class)->resolveEvidence($position, $flatObservedAtMs);

    parse_str((string) parse_url($capture->transactions[0]['request']->url(), PHP_URL_QUERY), $tradeQuery);

    expect($result->attribution)->toBe(PositionCloseAttribution::External)
        ->and($result->closingPrice)->toBe('0.09500660')
        ->and(array_map(
            static fn (array $transaction): string => (string) parse_url($transaction['request']->url(), PHP_URL_PATH),
            $capture->transactions,
        ))->toBe([
            '/fapi/v1/userTrades',
            '/fapi/v1/allAlgoOrders',
            '/fapi/v1/order',
            '/fapi/v1/forceOrders',
        ])
        ->and((int) $tradeQuery['endTime'])->toBe($flatObservedAtMs)
        ->and((int) $tradeQuery['startTime'])->toBe($position->opened_at->getTimestampMs())
        ->and($tradeQuery['limit'])->toBe('1000');
});

it('uses actualOrderId to keep a dropped Kraite algo execution automatic', function (): void {
    $position = attributionPosition('RESTALGO', hedgeMode: true);
    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => 'rest-kraite-algo-client',
        'exchange_order_id' => 'rest-kraite-algo-id',
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0.09',
        'quantity' => '0',
        'reference_price' => '0.09',
        'reference_quantity' => '0',
        'is_algo' => true,
    ]));
    $flatObservedAtMs = now()->getTimestampMs();
    $trade = attributionTrade('rest-generated-order', 'SELL', 'LONG', $flatObservedAtMs - 1_000);
    $algo = attributionAlgo(
        'rest-kraite-algo-id',
        'rest-generated-order',
        'SELL',
        'LONG',
        'rest-kraite-algo-client',
    );
    $capture = mockManualCloseBinance([
        new Response(200, [], json_encode([$trade], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([$algo], JSON_THROW_ON_ERROR)),
    ]);

    $result = app(PositionManualCloseAttributor::class)->resolve($position, $flatObservedAtMs);

    expect($result)->toBe(PositionCloseAttribution::Kraite)
        ->and(array_map(
            static fn (array $transaction): string => (string) parse_url($transaction['request']->url(), PHP_URL_PATH),
            $capture->transactions,
        ))->toBe(['/fapi/v1/userTrades', '/fapi/v1/allAlgoOrders']);
});

it('defensively ignores trades after the first flat observation', function (): void {
    $position = attributionPosition('RESTBOUND');
    $flatObservedAtMs = now()->getTimestampMs();
    $before = attributionTrade('before-flat-order', 'SELL', 'BOTH', $flatObservedAtMs - 1_000, 10);
    $after = attributionTrade('after-flat-order', 'SELL', 'BOTH', $flatObservedAtMs + 1_000, 20);
    $order = attributionOrder('before-flat-order', 'SELL', 'BOTH', true);
    $capture = mockManualCloseBinance([
        new Response(200, [], json_encode([$after, $before], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode($order, JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
    ]);

    $result = app(PositionManualCloseAttributor::class)->resolve($position, $flatObservedAtMs);

    parse_str((string) parse_url($capture->transactions[2]['request']->url(), PHP_URL_QUERY), $orderQuery);

    expect($result)->toBe(PositionCloseAttribution::External)
        ->and($orderQuery['orderId'])->toBe('before-flat-order');
});

it('leaves attribution unknown when Binance history is unavailable', function (): void {
    $position = attributionPosition('RESTFAIL');
    $capture = mockManualCloseBinance([
        new RuntimeException('Binance unavailable'),
    ]);

    $result = app(PositionManualCloseAttributor::class)->resolve($position, now()->getTimestampMs());

    expect($result)->toBe(PositionCloseAttribution::Unknown)
        ->and($capture->transactions)->toHaveCount(1)
        ->and($position->refresh()->manually_closed_at)->toBeNull();
});
