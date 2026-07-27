<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\UserDataStream\ProcessUserDataEventJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

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
beforeEach(function (): void {
    config()->set('kraite.user_data_stream.binance.dispatched_executions', [
        'TRADE', 'AMENDMENT', 'CANCELED', 'EXPIRED', 'REJECTED',
        'ALGO_NEW', 'ALGO_CANCELED', 'ALGO_EXPIRED', 'ALGO_FILLED',
    ]);
});

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

function buildActiveProfitOrderForPartialFill(string $exchangeOrderId): Order
{
    $token = 'PFP'.mb_strtoupper(Str::random(5));
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
        'binance_api_key' => 'partial-fill-test-key',
        'binance_api_secret' => 'partial-fill-test-secret',
    ]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'direction' => 'SHORT',
        'status' => 'active',
    ]);

    return Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => $exchangeOrderId,
        'type' => 'PROFIT-LIMIT',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '4.10500000',
        'quantity' => '20.99000000',
        'reference_price' => '4.10500000',
        'reference_quantity' => '20.99000000',
        'is_algo' => false,
    ]));
}

function correctionCountForPartialFill(Order $order): int
{
    return Steps::usingPrefix('trading', fn (): int => Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->count());
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

it('keeps the stated limit price during a partial fill instead of dispatching a false correction', function (): void {
    $order = buildActiveProfitOrderForPartialFill('partial-fill-price-'.Str::random(8));
    $payload = buildBinanceOrderUpdatePayload([
        's' => $order->position->parsed_trading_pair,
        'c' => $order->client_order_id,
        'i' => $order->exchange_order_id,
        'X' => 'PARTIALLY_FILLED',
        'x' => 'TRADE',
        'q' => '20.99',
        'z' => '9.45',
        'l' => '9.45',
        'p' => '4.105',
        'ap' => '4.10499999',
        'L' => '4.105',
    ]);

    expect($order->getRawOriginal('price'))->toBe('4.10500000')
        ->and($order->getRawOriginal('reference_price'))->toBe('4.10500000')
        ->and($order->status)->toBe('NEW')
        ->and(correctionCountForPartialFill($order))->toBe(0);

    (new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();

    $fresh = $order->fresh();

    expect($fresh->status)->toBe('PARTIALLY_FILLED')
        ->and($fresh->getRawOriginal('price'))->toBe('4.10500000')
        ->and($fresh->getRawOriginal('reference_price'))->toBe('4.10500000')
        ->and($fresh->getRawOriginal('quantity'))->toBe('20.99000000')
        ->and(correctionCountForPartialFill($fresh))->toBe(0);
});

it('still dispatches correction when an amendment changes the stated limit price', function (): void {
    $order = buildActiveProfitOrderForPartialFill('amended-price-'.Str::random(8));
    $payload = buildBinanceOrderUpdatePayload([
        's' => $order->position->parsed_trading_pair,
        'c' => $order->client_order_id,
        'i' => $order->exchange_order_id,
        'X' => 'NEW',
        'x' => 'AMENDMENT',
        'q' => '20.99',
        'z' => '0',
        'l' => '0',
        'p' => '4.100',
        'ap' => '0',
        'L' => '0',
    ]);

    expect($order->getRawOriginal('price'))->toBe('4.10500000')
        ->and(correctionCountForPartialFill($order))->toBe(0);

    (new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();

    $fresh = $order->fresh();

    expect($fresh->status)->toBe('NEW')
        ->and($fresh->getRawOriginal('price'))->toBe('4.10000000')
        ->and($fresh->getRawOriginal('reference_price'))->toBe('4.10500000')
        ->and(correctionCountForPartialFill($fresh))->toBe(1);
});

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
    expect($fresh->status)
        ->toBe('FILLED', 'A late PARTIALLY_FILLED frame must not regress terminal exchange truth.');
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

/**
 * Pin the fix for the 2026-07-26 TOSHIUSDT incident.
 *
 * The bug: burst fills (a ~1M-unit MARKET on a sub-cent token executes
 * as several partial trades within one second) delivered user-data
 * frames out of order. The OrderObserver reverted the STATUS regression
 * (FILLED stayed FILLED), but `applyToOrderModel` still wrote the stale
 * working frame's literal price — 0 for MARKET frames — over the FILLED
 * frame's real average (0.00011640). The drift spotter then computed a
 * local weighted entry of 0 against the exchange's 0.000116 and alerted
 * every 5 minutes until the position closed. Sync could not heal it:
 * MARKET orders are excluded from polling sync by design.
 *
 * Fix: `applyToOrderModel` rejects any frame ranked below the local
 * order's lifecycle rank (stale-burst protection), and never overwrites
 * a positive price with zero.
 */
it('does not zero a filled MARKET price when a stale NEW frame arrives after FILLED', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: 'toshi-stale-new-'.Str::random(6), originalQuantity: '1001816', initialStatus: 'FILLED');
    $order->updateQuietly(['price' => '0.00011640']);

    $payload = buildBinanceOrderUpdatePayload([
        's' => $order->position->parsed_trading_pair,
        'c' => $order->client_order_id,
        'i' => $order->exchange_order_id,
        'X' => 'NEW',
        'x' => 'TRADE',
        'q' => '1001816',
        'z' => '0',
        'l' => '0',
        'p' => '0',
        'ap' => '0',
        'L' => '0',
    ]);

    expect($order->fresh()->getRawOriginal('price'))->toBe('0.00011640');

    (new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();

    $fresh = $order->fresh();

    expect($fresh->getRawOriginal('price'))
        ->toBe('0.00011640', 'A stale NEW frame after FILLED must not zero the executed average price.');
    expect($fresh->status)->toBe('FILLED');
});

it('does not zero a filled MARKET price when a stale PARTIALLY_FILLED frame trails the FILLED frame', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: 'toshi-stale-partial-'.Str::random(6), originalQuantity: '1001816', initialStatus: 'FILLED');
    $order->updateQuietly(['price' => '0.00011640']);

    // The exact TOSHIUSDT trailing frame: working status, real running
    // average in `ap`, but literal `p` = 0 (MARKET). Pre-fix the working
    // branch selected `p` and zeroed the price.
    $payload = buildBinanceOrderUpdatePayload([
        's' => $order->position->parsed_trading_pair,
        'c' => $order->client_order_id,
        'i' => $order->exchange_order_id,
        'X' => 'PARTIALLY_FILLED',
        'x' => 'TRADE',
        'q' => '1001816',
        'z' => '500000',
        'l' => '500000',
        'p' => '0',
        'ap' => '0.00011640',
        'L' => '0.00011640',
    ]);

    (new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();

    $fresh = $order->fresh();

    expect($fresh->getRawOriginal('price'))
        ->toBe('0.00011640', 'A trailing PARTIALLY_FILLED frame must not regress a filled order.');
    expect($fresh->status)->toBe('FILLED');
});

it('replays the TOSHIUSDT out-of-order burst and lands on the executed average price', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: 'toshi-burst-'.Str::random(6), originalQuantity: '1001816', initialStatus: 'NEW');
    $order->updateQuietly(['price' => '0.00000000']);

    $frames = [
        ['X' => 'PARTIALLY_FILLED', 'z' => '400000', 'l' => '400000', 'ap' => '0.00011640', 'L' => '0.00011640'],
        ['X' => 'FILLED', 'z' => '1001816', 'l' => '601816', 'ap' => '0.00011640', 'L' => '0.00011650'],
        // Stale frames processed after the terminal one — the prod sequence.
        ['X' => 'NEW', 'z' => '0', 'l' => '0', 'ap' => '0', 'L' => '0'],
        ['X' => 'PARTIALLY_FILLED', 'z' => '400000', 'l' => '400000', 'ap' => '0.00011640', 'L' => '0.00011640'],
    ];

    foreach ($frames as $frame) {
        (new ProcessUserDataEventJob(
            accountId: $order->position->account_id,
            apiSystemId: $order->position->account->api_system_id,
            apiSystemCanonical: 'binance',
            payload: buildBinanceOrderUpdatePayload(array_merge([
                's' => $order->position->parsed_trading_pair,
                'c' => $order->client_order_id,
                'i' => $order->exchange_order_id,
                'x' => 'TRADE',
                'q' => '1001816',
                'p' => '0',
            ], $frame)),
        ))->compute();
    }

    $fresh = $order->fresh();

    expect($fresh->getRawOriginal('price'))
        ->toBe('0.00011640', 'The executed average must survive the stale tail of the burst.');
    expect($fresh->status)->toBe('FILLED');
    expect($fresh->getRawOriginal('quantity'))->toBe('1001816.00000000');
});

it('keeps a known positive price when a same-rank terminal frame carries no usable price', function (): void {
    $order = buildOrderForUserDataEvent(exchangeOrderId: 'toshi-degenerate-'.Str::random(6), originalQuantity: '1001816', initialStatus: 'FILLED');
    $order->updateQuietly(['price' => '0.00011640']);

    // Same-rank frame (FILLED after FILLED) passes the monotonicity
    // guard, but both `p` and `ap` are 0 — the zero-clobber guard must
    // preserve the known executed price.
    $payload = buildBinanceOrderUpdatePayload([
        's' => $order->position->parsed_trading_pair,
        'c' => $order->client_order_id,
        'i' => $order->exchange_order_id,
        'X' => 'FILLED',
        'x' => 'TRADE',
        'q' => '1001816',
        'z' => '1001816',
        'l' => '0',
        'p' => '0',
        'ap' => '0',
        'L' => '0',
    ]);

    (new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();

    $fresh = $order->fresh();

    expect($fresh->getRawOriginal('price'))
        ->toBe('0.00011640', 'A terminal frame without execution price must not destroy the known average.');
    expect($fresh->status)->toBe('FILLED');
});

it('routes a rejected exchange order immediately into replacement', function (): void {
    $order = buildActiveProfitOrderForPartialFill('rejected-'.Str::random(8));
    $payload = buildBinanceOrderUpdatePayload([
        's' => $order->position->parsed_trading_pair,
        'c' => $order->client_order_id,
        'i' => $order->exchange_order_id,
        'X' => 'REJECTED',
        'x' => 'REJECTED',
        'q' => '20.99',
        'z' => '0',
        'l' => '0',
        'p' => '4.105',
        'ap' => '0',
        'L' => '0',
    ]);

    (new ProcessUserDataEventJob(
        accountId: $order->position->account_id,
        apiSystemId: $order->position->account->api_system_id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute();

    $replacement = Steps::usingPrefix('trading', fn () => Step::query()
        ->forRelatable($order->position)
        ->forClasses(PreparePositionReplacementJob::class)
        ->first());

    expect($order->fresh()->status)->toBe('REJECTED')
        ->and($replacement)->not->toBeNull()
        ->and($replacement->arguments['triggerStatus'])->toBe('REJECTED');
});
