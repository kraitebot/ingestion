<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Drift\DriftCheckService;
use Kraite\Core\Support\Drift\OrderDriftReport;
use Kraite\Core\Support\Drift\PositionDriftReport;

uses(RefreshDatabase::class)->group('unit', 'drift');

/**
 * Spins up an active LONG position with a single FILLED MARKET entry at
 * $1.00 / qty=10, plus optional algo orders. Returns the position with
 * orders hydrated so the service can pair them. The fixture mirrors the
 * shape the spotter cron will see in production.
 */
function makeDriftFixture(
    string $token = 'DRIFT',
    string $status = 'active',
    string $entryPrice = '1.00000000',
    string $quantity = '10.00000000',
): array {
    $token .= mb_strtoupper(Str::random(4));

    $apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create([
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
        'margin_mode' => 'CROSSED',
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => $status,
        'opening_price' => $entryPrice,
        'quantity' => $quantity,
        'leverage' => 10,
    ]);

    return [
        'token' => $token,
        'pair' => $token.'USDT',
        'account' => $account,
        'position' => $position,
    ];
}

function makeOrder(int $positionId, array $overrides = []): Order
{
    return Order::withoutEvents(fn () => Order::create(array_merge([
        'position_id' => $positionId,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => (string) random_int(10_000_000, 99_999_999),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '1.00000000',
        'quantity' => '10.00000000',
        'reference_price' => '1.00000000',
        'reference_quantity' => '10.00000000',
        'is_algo' => false,
    ], $overrides)));
}

it('returns synced when DB and exchange agree on every field', function (): void {
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'price' => '1.00000000',
        'quantity' => '10.00000000',
    ]);
    $tp = makeOrder($position->id, [
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'status' => 'NEW',
        'price' => '1.10000000',
        'quantity' => '10.00000000',
        'is_algo' => true,
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'],
        'positionSide' => 'LONG',
        'positionAmt' => '10',
        'entryPrice' => '1.00000000',
        'leverage' => '10',
        'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = [[
        'symbol' => $f['pair'],
        'clientOrderId' => $tp->client_order_id,
        'orderId' => $tp->exchange_order_id,
        'positionSide' => 'LONG',
        'side' => 'SELL',
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '1.10000000',
        'origQty' => '10.00000000',
    ]];

    $report = (new DriftCheckService)->analyse(
        $f['account'],
        [$position],
        $exchangePositions,
        $exchangeOrders,
    );

    expect($report->positions)->toHaveCount(1);
    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_SYNCED);
    expect($report->positions[0]->positionDriftFields)->toBe([]);
    expect($report->driftingPositions())->toHaveCount(0);
});

it('reports an HTTP 200 vendor error as snapshot failure instead of db-only drift', function (): void {
    Http::fake([
        '*' => Http::response(json_encode([
            'code' => '40014',
            'msg' => 'invalid api key',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'DRIFTFAIL']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'DRIFTFAIL',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'bitget_api_key' => 'TESTKEY',
        'bitget_api_secret' => 'TESTSECRET',
        'bitget_passphrase' => 'TESTPASS',
    ]);
    Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'DRIFTFAILUSDT',
        'status' => 'active',
    ]);

    $report = (new DriftCheckService)->analyseAccount($account);

    expect($report->apiError)->toContain('Invalid bitget positions response')
        ->and($report->positions)->toBe([])
        ->and($report->orphanOrders)->toBe([]);
});

it('flags WAP price drift on a PROFIT-LIMIT order when exchange has the post-WAP price', function (): void {
    // Scenario: position got a DCA fill, WAP recomputed the TP and pushed
    // it to the exchange via apiModify, but the DB-side persist failed.
    // DB still holds the pre-WAP price (1.10), exchange now reports the
    // new WAP'd price (1.0750). The 4.5% drift is well outside the 0.1%
    // tolerance band, so the service must flag it.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET',
        'status' => 'FILLED',
        'price' => '1.00000000',
        'quantity' => '10.00000000',
    ]);
    $tp = makeOrder($position->id, [
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'status' => 'NEW',
        'price' => '1.10000000',
        'quantity' => '10.00000000',
        'is_algo' => true,
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'],
        'positionSide' => 'LONG',
        'positionAmt' => '10',
        'entryPrice' => '1.00000000',
        'leverage' => '10',
        'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = [[
        'symbol' => $f['pair'],
        'clientOrderId' => $tp->client_order_id,
        'orderId' => $tp->exchange_order_id,
        'positionSide' => 'LONG',
        'side' => 'SELL',
        'type' => 'LIMIT',
        'status' => 'NEW',
        'price' => '1.07500000',
        'origQty' => '10.00000000',
    ]];

    $report = (new DriftCheckService)->analyse(
        $f['account'],
        [$position],
        $exchangePositions,
        $exchangeOrders,
    );

    $pair = $report->positions[0];
    expect($pair->status)->toBe(PositionDriftReport::STATUS_DRIFT);
    expect($pair->driftedOrders())->toHaveCount(1);
    expect($pair->driftedOrders()[0]->driftFields)->toContain('price');
});

it('treats sub-tolerance price differences as synced', function (): void {
    // Same shape as the WAP test, but the price gap (0.05%) is inside
    // the 0.1% tolerance — exchange reports a few extra decimals from
    // its volume-weighted averaging. Must NOT raise drift.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    $tp = makeOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = [[
        'symbol' => $f['pair'],
        'clientOrderId' => $tp->client_order_id,
        'orderId' => $tp->exchange_order_id,
        'positionSide' => 'LONG', 'side' => 'SELL', 'type' => 'LIMIT', 'status' => 'NEW',
        'price' => '1.10055000', // +0.05% — inside tolerance
        'origQty' => '10.00000000',
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, $exchangeOrders);

    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_SYNCED);
});

it('flags db_only when a non-FILLED order is missing from exchange (the missed-fill regression)', function (): void {
    // Bruno's exact original incident: a LIMIT order filled on the
    // exchange but the bot never caught it. From this snapshot's POV the
    // order is still NEW in DB but absent from the open-orders endpoint.
    // The service must classify the order as db_only and the pair as
    // drift so the spotter can dispatch a heal.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    makeOrder($position->id, [
        'type' => 'LIMIT', 'side' => 'BUY', 'status' => 'NEW',
        'price' => '0.95000000', 'quantity' => '5.00000000',
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = []; // The limit silently filled — exchange dropped it.

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, $exchangeOrders);

    $pair = $report->positions[0];
    expect($pair->status)->toBe(PositionDriftReport::STATUS_DRIFT);
    $orders = $pair->driftedOrders();
    expect($orders)->toHaveCount(1);
    expect($orders[0]->status)->toBe(OrderDriftReport::STATUS_DB_ONLY);
});

it('treats a FILLED order absent on the exchange as expected (synced, not drift)', function (): void {
    // FILLED orders no longer appear on the open-orders endpoint. We
    // must NOT raise drift purely on their absence — that would alarm
    // every healthy averaged position.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, []);

    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_SYNCED);
});

it('flags position-level quantity drift when our DB qty disagrees with the exchange', function (): void {
    // Position-level qty drift: e.g. a partial fill happened on exchange
    // and our DB never caught it. Exchange shows positionAmt=15 vs our 10.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '15',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, []);

    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_DRIFT);
    expect($report->positions[0]->positionDriftFields)->toContain('quantity');
});

it('returns transient (no false drift) for a mid-flight DB position in syncing status', function (): void {
    $f = makeDriftFixture(status: 'syncing');
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    $tp = makeOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    $position->load('orders');

    // Wildly different prices — should still NOT raise drift because
    // the position is mid-flight (the dispatcher is mid-write).
    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = [[
        'symbol' => $f['pair'],
        'clientOrderId' => $tp->client_order_id, 'orderId' => $tp->exchange_order_id,
        'positionSide' => 'LONG', 'side' => 'SELL', 'type' => 'LIMIT', 'status' => 'NEW',
        'price' => '99.00000000',
        'origQty' => '10.00000000',
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, $exchangeOrders);

    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_TRANSIENT);
    expect($report->driftingPositions())->toHaveCount(0);
});

it('suppresses qty drift on close-position types when the exchange reports qty=0', function (): void {
    // BitGet plan/algo TP/SL orders report qty=0 (they close whatever's
    // open at trigger time). Comparing against the DB's bound qty would
    // always look drifted. Service must skip qty for these types.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    $sl = makeOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = [[
        'symbol' => $f['pair'],
        'clientOrderId' => $sl->client_order_id, 'orderId' => $sl->exchange_order_id,
        'positionSide' => 'LONG', 'side' => 'SELL',
        '_orderType' => 'STOP_MARKET',
        'status' => 'NEW',
        'price' => '0.90000000',
        'origQty' => '0', // BitGet-style 0 qty — must not flag drift
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, $exchangeOrders);

    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_SYNCED);
});

it('treats PROFIT-LIMIT (DB) and LIMIT (exchange) as the same type via alias matching', function (): void {
    // Post Dec-2025 Binance algo migration: our PROFIT-LIMIT lands on
    // Binance as a reduce-only LIMIT. The comparator must alias-match.
    $f = makeDriftFixture();
    $position = $f['position']->fresh('orders');

    makeOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    $tp = makeOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    $position->load('orders');

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];
    $exchangeOrders = [[
        'symbol' => $f['pair'],
        'clientOrderId' => $tp->client_order_id, 'orderId' => $tp->exchange_order_id,
        'positionSide' => 'LONG', 'side' => 'SELL',
        'type' => 'LIMIT', // Binance reports LIMIT, not PROFIT-LIMIT
        'status' => 'NEW',
        'price' => '1.10000000',
        'origQty' => '10.00000000',
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [$position], $exchangePositions, $exchangeOrders);

    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_SYNCED);
});

it('flags exchange_only when a position exists on the exchange but not in DB', function (): void {
    // Manual user activity or a DB write that never landed. The pair has
    // no DB side, only an exchange side. Status must surface this.
    $f = makeDriftFixture();
    $f['position']->update(['status' => 'closed']);

    $exchangePositions = [[
        'symbol' => $f['pair'], 'positionSide' => 'LONG', 'positionAmt' => '10',
        'entryPrice' => '1.00000000', 'leverage' => '10', 'marginType' => 'CROSSED',
    ]];

    $report = (new DriftCheckService)->analyse($f['account'], [], $exchangePositions, []);

    expect($report->positions)->toHaveCount(1);
    expect($report->positions[0]->status)->toBe(PositionDriftReport::STATUS_EXCHANGE_ONLY);
});
