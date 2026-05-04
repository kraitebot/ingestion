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

/**
 * Pin the fix for the 2026-05-04 ONDO #271 incident.
 *
 * The bug: `ProcessUserDataEventJob::applyToOrderModel` was writing
 * the WS event's `filledQuantity` (cumulative-executed) into the
 * local `orders.quantity` column, which is supposed to hold the
 * ORIGINAL placed quantity. During multi-fill MARKETs on thin
 * books, intermediate `ORDER_TRADE_UPDATE` PARTIALLY_FILLED frames
 * carry e.g. `filledQuantity=18.5` of an `originalQuantity=81.9`
 * order, and that 18.5 was overwriting the local row's 81.9 — only
 * to be later "corrected" back to 81.9 on the FILLED frame, with
 * out-of-order PARTIALLY_FILLED frames re-corrupting it again.
 *
 * Position 271 ONDOUSDT timeline (model_logs):
 *   04:48:18  status=NEW, quantity=81.9 (original placement)
 *   04:48:19  status=PARTIALLY_FILLED, quantity=81.9→18.5 (corrupted)
 *   04:48:19  status=NEW→FILLED, reference_quantity=NULL→18.5 (captured stale)
 *   04:48:20  quantity=18.5→81.9 (corrected by FILLED frame)
 *   04:48:20  status=FILLED→PARTIALLY_FILLED (regression — separate bug)
 *   04:48:20  quantity=81.9→35.8 (re-corrupted by late PARTIAL frame)
 *   04:48:30  status=PARTIALLY_FILLED→FILLED, quantity=35.8→81.9
 *
 * `ActivatePositionJob::validateReferenceFields` then threw with
 * `MARKET order #1551 quantity drift: reference_quantity=18.5,
 * quantity=81.9` because reference_quantity had been captured at
 * a moment when quantity was mid-corruption. Lifecycle aborted →
 * cancel-cascade → MARKET-CANCEL SELL flat-closed the long near
 * breakeven, fees ate the rest = negative trade.
 *
 * Fix: `applyToOrderModel` MUST keep `orders.quantity` frozen at
 * the originally placed value. WS pushes only update `status` (and
 * `price` from `averagePrice` for filled orders). Cumulative fill
 * progress lives ONLY in `api_data_stream` rows, not on the local
 * Order model.
 */
function buildOrderForUserDataEvent(string $exchangeOrderId, string $originalQuantity, string $initialStatus = 'NEW'): Order
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'QFREEZE']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'QFREEZE',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'test-api-key',
        'binance_api_secret' => 'test-api-secret',
    ]);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'QFREEZEUSDT',
        'direction' => 'LONG',
        'status' => 'opening',
    ]);

    return Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => $exchangeOrderId,
        'type' => 'MARKET',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'status' => $initialStatus,
        'reference_status' => 'NEW',
        'price' => '1.00000000',
        'quantity' => $originalQuantity,
        'reference_price' => '1.00000000',
        'reference_quantity' => $originalQuantity,
        'is_algo' => false,
    ]));
}

function buildBinanceOrderUpdatePayload(array $orderOverrides): array
{
    return [
        'e' => 'ORDER_TRADE_UPDATE',
        'E' => 1730000000000,
        'o' => array_merge([
            's' => 'QFREEZEUSDT',
            'c' => 'test-client-id',
            'i' => '9999000111',
            'X' => 'PARTIALLY_FILLED',
            'x' => 'TRADE',
            'q' => '81.9',
            'z' => '18.5',
            'l' => '18.5',
            'p' => '0',
            'ap' => '0.29750',
            'L' => '0.29750',
            'R' => false,
        ], $orderOverrides),
    ];
}

it('preserves quantity at the originally placed value across PARTIALLY_FILLED frames', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: '9999000111', originalQuantity: '81.9');

    $payload = buildBinanceOrderUpdatePayload([
        'i' => '9999000111',
        'X' => 'PARTIALLY_FILLED',
        'q' => '81.9',
        'z' => '18.5',
    ]);

    $job = new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    );

    $job->compute();

    $fresh = Order::find($order->id);

    expect((float) $fresh->quantity)
        ->toBe(81.9, 'PARTIALLY_FILLED frames carry cumulative `filledQuantity` (18.5) — must NEVER overwrite the placed quantity (81.9).');
    expect($fresh->status)
        ->toBe('PARTIALLY_FILLED', 'Status update should still flow through.');
});

it('preserves quantity on a FILLED frame (cumulative filled equals original)', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: '9999000222', originalQuantity: '81.9', initialStatus: 'PARTIALLY_FILLED');

    $payload = buildBinanceOrderUpdatePayload([
        'i' => '9999000222',
        'X' => 'FILLED',
        'q' => '81.9',
        'z' => '81.9',
    ]);

    $job = new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    );

    $job->compute();

    $fresh = Order::find($order->id);

    expect((float) $fresh->quantity)->toBe(81.9);
    expect($fresh->status)->toBe('FILLED');
});

it('does not regress quantity from a late PARTIALLY_FILLED frame after FILLED has landed', function (): void {
    // Repro of the ONDO #271 04:48:20 race: an out-of-order
    // PARTIALLY_FILLED frame arrives AFTER the FILLED frame. The
    // local row was already at status=FILLED, quantity=81.9 (the
    // restored value). The late frame must NOT regress quantity to
    // its cumulative-filled value (35.8).
    $order = buildOrderForUserDataEvent(exchangeOrderId: '9999000333', originalQuantity: '81.9', initialStatus: 'FILLED');

    $payload = buildBinanceOrderUpdatePayload([
        'i' => '9999000333',
        'X' => 'PARTIALLY_FILLED',
        'q' => '81.9',
        'z' => '35.8',
    ]);

    $job = new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    );

    $job->compute();

    $fresh = Order::find($order->id);

    expect((float) $fresh->quantity)
        ->toBe(81.9, 'A late PARTIALLY_FILLED frame must not corrupt the stable post-FILLED quantity.');
});

it('still updates status and average price on a normal NEW→PARTIALLY_FILLED→FILLED progression', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: '9999000444', originalQuantity: '81.9');

    $payload = buildBinanceOrderUpdatePayload([
        'i' => '9999000444',
        'X' => 'FILLED',
        'q' => '81.9',
        'z' => '81.9',
        'ap' => '0.29760',
    ]);

    $job = new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    );

    $job->compute();

    $fresh = Order::find($order->id);

    expect($fresh->status)->toBe('FILLED');
    expect((float) $fresh->price)->toBe(0.29760);
    expect((float) $fresh->quantity)->toBe(81.9);
});
