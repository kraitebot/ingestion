<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * 2026-05-04 — `CancelPositionOpenOrdersJob` reshaped to per-order
 * iteration on every exchange (was: symbol-wide DELETE on
 * Binance/Kucoin/Bybit, per-order on Bitget only).
 *
 * The smoking gun this pin defends against: Binance's
 * `/fapi/v1/allOpenOrders` cancel is symbol-level, not position-level.
 * A previous Kraite position closing on a symbol that another active
 * Kraite position holds orders on (sequential open-close-reopen — the
 * normal trading pattern) wiped the active position's TP + LIMIT
 * ladder as collateral damage. Position 211 on 2026-05-03 22:50 is
 * the canonical incident.
 *
 * Per-order cancel:
 *   - touches only orders the cancelling position owns locally
 *   - no cross-position blast radius regardless of exchange
 *   - same code path on every canonical (no Bitget special case)
 *   - bumps `reference_status` to CANCELLED before issuing the call
 *     so the OrderObserver's intent gate skips the replacement
 *     dispatch when the cancellation lands via WS push
 */
function buildPositionWithOpenOrders(string $token, string $status = 'cancelling'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'test-api-key',
        'binance_api_secret' => 'test-api-secret',
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'direction' => 'LONG',
        'status' => $status,
    ]);
}

function attachOrder(Position $position, array $overrides = []): Order
{
    return Order::withoutEvents(fn () => Order::create(array_merge([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => (string) random_int(10_000_000, 99_999_999),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.00000000',
        'quantity' => '10.00000000',
        'reference_price' => '1.00000000',
        'reference_quantity' => '10.00000000',
        'is_algo' => false,
    ], $overrides)));
}

function fakeBinanceCancelEndpoint(): void
{
    Http::fake([
        '*/fapi/v1/order*' => Http::response([
            'orderId' => 99,
            'symbol' => 'STUBUSDT',
            'status' => 'CANCELED',
            'price' => '1.00',
            'avgPrice' => '0.00',
            'origQty' => '10.0',
            'executedQty' => '0.0',
            'cumQuote' => '0.0',
            'side' => 'BUY',
            'positionSide' => 'LONG',
            'type' => 'LIMIT',
            'origType' => 'LIMIT',
            'reduceOnly' => false,
            'closePosition' => false,
            'workingType' => 'CONTRACT_PRICE',
            'priceProtect' => false,
            'stopPrice' => '0.0',
            'timeInForce' => 'GTC',
            'updateTime' => 1_700_000_000_000,
            'time' => 1_700_000_000_000,
            'clientOrderId' => 'fake-client-id',
        ], 200),
    ]);
}

it('iterates the position\'s own non-algo orders and never calls the symbol-wide endpoint', function (): void {
    $position = buildPositionWithOpenOrders('PERA');

    $orderIds = [];
    for ($i = 0; $i < 4; $i++) {
        $orderIds[] = attachOrder($position, [
            'price' => sprintf('%.8f', 1 - ($i * 0.05)),
            'exchange_order_id' => "ORD-PERA-{$i}",
        ])->exchange_order_id;
    }

    fakeBinanceCancelEndpoint();

    $job = new CancelPositionOpenOrdersJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result)->toBeArray();
    expect($result['cancelled_count'] ?? null)->toBe(4);

    $deleteCalls = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => $req->method() === 'DELETE');

    expect($deleteCalls)->toHaveCount(
        4,
        'Each of the position\'s own orders must be cancelled with one targeted DELETE call.'
    );

    foreach ($deleteCalls as $req) {
        expect($req->url())
            ->toContain('/fapi/v1/order')
            ->not->toContain('/fapi/v1/allOpenOrders');
    }

    $hitIds = $deleteCalls
        ->map(fn ($req) => $req->url())
        ->map(fn (string $url): ?string => (function (string $u): ?string {
            preg_match('/orderId=([^&]+)/', $u, $m);

            return $m[1] ?? null;
        })($url))
        ->filter()
        ->values()
        ->all();

    sort($hitIds);
    sort($orderIds);
    expect($hitIds)->toBe($orderIds);
});

it('does not touch a sibling position\'s orders on the same symbol+account', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $symbol = Symbol::factory()->create(['token' => 'PERB']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'PERB',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'test-api-key',
        'binance_api_secret' => 'test-api-secret',
    ]);

    // Mirrors the 2026-05-03 ETCUSDT incident: position 209 sits in
    // a terminal `cancelled` status with stuck NEW orders that the
    // drift watchdog re-targets for cancellation; position 211 has
    // already opened on the same symbol+account+direction and is
    // active. The DB's unique open-slot index excludes terminal
    // statuses, so the two coexist by design.
    $cancelling = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'PERBUSDT',
        'direction' => 'LONG',
        'status' => 'cancelled',
    ]);

    $sibling = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'PERBUSDT',
        'direction' => 'LONG',
        'status' => 'active',
    ]);

    // 4 LIMITs on each position — same symbol, same account, distinct exchange ids.
    for ($i = 0; $i < 4; $i++) {
        attachOrder($cancelling, ['exchange_order_id' => "CANC-{$i}"]);
        attachOrder($sibling, ['exchange_order_id' => "SIB-{$i}"]);
    }

    fakeBinanceCancelEndpoint();

    $job = new CancelPositionOpenOrdersJob($cancelling->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result['cancelled_count'] ?? null)->toBe(4);

    $hitIds = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => $req->method() === 'DELETE')
        ->map(function ($req): ?string {
            preg_match('/orderId=([^&]+)/', $req->url(), $m);

            return $m[1] ?? null;
        })
        ->filter()
        ->values()
        ->all();

    foreach ($hitIds as $id) {
        expect($id)->toStartWith(
            'CANC-',
            'A symbol-wide cancel would mix sibling order ids in here. '
            .'Per-order iteration scoped to the cancelling position must never touch SIB-* ids.'
        );
    }

    foreach ($sibling->orders()->get() as $siblingOrder) {
        expect($siblingOrder->status)->toBe('NEW');
        expect($siblingOrder->reference_status)->toBe('NEW');
    }
});

it('bumps reference_status to CANCELLED on the cancelling position\'s orders before the api call', function (): void {
    $position = buildPositionWithOpenOrders('PERC');

    $orders = collect(range(0, 3))->map(fn ($i) => attachOrder($position, [
        'exchange_order_id' => "RC-{$i}",
    ]));

    fakeBinanceCancelEndpoint();

    $job = new CancelPositionOpenOrdersJob($position->id);
    $job->assignExceptionHandler();
    $job->computeApiable();

    foreach ($orders as $order) {
        $fresh = Order::find($order->id);
        expect($fresh->reference_status)->toBe(
            'CANCELLED',
            'Pre-bumping reference_status is the OrderObserver intent gate — '
            .'when the matching WS push lands, status===reference_status and '
            .'the replacement dispatch correctly stays quiet.'
        );
    }
});

it('skips algo, terminal, and ghost rows', function (): void {
    $position = buildPositionWithOpenOrders('PERD');

    $cancellable = attachOrder($position, ['exchange_order_id' => 'GOOD-1']);

    // Algo row — handled by CancelAlgoOpenOrdersJob, not this job.
    attachOrder($position, ['type' => 'STOP-MARKET', 'is_algo' => true, 'exchange_order_id' => 'ALGO-1']);

    // Terminal row — already finished.
    attachOrder($position, ['status' => 'FILLED', 'exchange_order_id' => 'TERM-1']);

    // Ghost row — was never placed on the exchange.
    attachOrder($position, ['exchange_order_id' => null]);

    fakeBinanceCancelEndpoint();

    $job = new CancelPositionOpenOrdersJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result['cancelled_count'] ?? null)->toBe(1);

    $hitIds = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => $req->method() === 'DELETE')
        ->map(function ($req): ?string {
            preg_match('/orderId=([^&]+)/', $req->url(), $m);

            return $m[1] ?? null;
        })
        ->filter()
        ->values()
        ->all();

    expect($hitIds)->toBe(['GOOD-1']);
    expect(Order::find($cancellable->id)->reference_status)->toBe('CANCELLED');
});

it('cancels only opening LIMIT orders when openingOrdersOnly is enabled', function (): void {
    $position = buildPositionWithOpenOrders('PERE');
    $openingLimit = attachOrder($position, [
        'type' => 'LIMIT',
        'exchange_order_id' => 'OPENING-LIMIT-1',
    ]);
    $takeProfit = attachOrder($position, [
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'exchange_order_id' => 'TAKE-PROFIT-1',
    ]);

    fakeBinanceCancelEndpoint();

    $job = new CancelPositionOpenOrdersJob($position->id, openingOrdersOnly: true);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    $hitIds = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'DELETE')
        ->map(function ($request): ?string {
            preg_match('/orderId=([^&]+)/', $request->url(), $matches);

            return $matches[1] ?? null;
        })
        ->filter()
        ->values()
        ->all();

    expect($result['cancelled_count'])->toBe(1)
        ->and($hitIds)->toBe(['OPENING-LIMIT-1'])
        ->and($openingLimit->fresh()->reference_status)->toBe('CANCELLED')
        ->and($takeProfit->fresh()->status)->toBe('NEW')
        ->and($takeProfit->fresh()->reference_status)->toBe('NEW');
});

it('keeps cancelling every non-algo order by default', function (): void {
    $position = buildPositionWithOpenOrders('PERF');
    attachOrder($position, ['type' => 'LIMIT', 'exchange_order_id' => 'DEFAULT-LIMIT-1']);
    attachOrder($position, [
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'exchange_order_id' => 'DEFAULT-TP-1',
    ]);

    fakeBinanceCancelEndpoint();

    $job = new CancelPositionOpenOrdersJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result['cancelled_count'])->toBe(2);
});
