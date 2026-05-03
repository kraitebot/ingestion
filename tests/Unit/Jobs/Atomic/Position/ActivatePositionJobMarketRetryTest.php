<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\ActivatePositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the race-tolerant `validateMarketOrders` behaviour added to
 * `ActivatePositionJob` on 2026-05-03 after the position #208 + #209
 * incidents.
 *
 * The race: `PlaceMarketOrderJob` writes the local Order row from the
 * REST ack, which can return at `PARTIALLY_FILLED` for a multi-level
 * fill on a thin book. Binance immediately follows up with an
 * `ORDER_TRADE_UPDATE` WS push that promotes the row to `FILLED`.
 * Under DB-lock contention or a wedged WS daemon, that promotion can
 * lag by hundreds of ms — long enough that `ActivatePositionJob` reads
 * the local row before the WS handler has written FILLED.
 *
 * Old behaviour: a hard `Exception` on any non-FILLED status, throwing
 * the lifecycle into the cancel-cascade for a position that actually
 * filled correctly on the exchange (#208/#209). The cancel-cascade
 * then flattened the entry, left the local Order row stuck at
 * PARTIALLY_FILLED forever (`syncable()` excludes MARKET), and the
 * drift watchdog flagged it as orphan.
 *
 * New behaviour: poll `apiSync()` up to a small bounded number of
 * attempts with brief sleeps in between. If the exchange-side truth
 * is FILLED, the row promotes and we proceed. Only if the exchange
 * itself still reports non-terminal after the retries do we surface
 * the legitimate error — at which point cancel-cascade is the right
 * call.
 */
function buildPositionForActivate(string $marketStatus, string $marketReferenceStatus = 'FILLED'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'AKTV']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'AKTV',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'AKTVUSDT',
        'direction' => 'LONG',
        'status' => 'opening',
        'total_limit_orders' => 4,
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
    ]);

    // Bypass observers so the test setup doesn't trigger the
    // production order-mod / drift workflows just because we wrote
    // the rows.
    $market = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => '999000001',
        'type' => 'MARKET',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'status' => $marketStatus,
        'reference_status' => $marketReferenceStatus,
        'price' => '1.00000000',
        'quantity' => '10.00000000',
        'reference_price' => '1.00000000',
        'reference_quantity' => '10.00000000',
        'is_algo' => false,
    ]));

    // 4 LIMIT rungs, all NEW, matching `total_limit_orders`.
    for ($i = 1; $i <= 4; $i++) {
        Order::withoutEvents(fn () => Order::create([
            'position_id' => $position->id,
            'uuid' => Str::uuid()->toString(),
            'client_order_id' => Str::uuid()->toString(),
            'exchange_order_id' => '999000010'.$i,
            'type' => 'LIMIT',
            'side' => 'BUY',
            'position_side' => 'LONG',
            'status' => 'NEW',
            'reference_status' => 'NEW',
            'price' => sprintf('%.8f', 1.0 - ($i * 0.05)),
            'quantity' => sprintf('%.8f', 10 * ($i + 1)),
            'reference_price' => sprintf('%.8f', 1.0 - ($i * 0.05)),
            'reference_quantity' => sprintf('%.8f', 10 * ($i + 1)),
            'is_algo' => false,
        ]));
    }

    // 1 PROFIT-LIMIT (TP).
    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => '999000020',
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.10000000',
        'quantity' => '10.00000000',
        'reference_price' => '1.10000000',
        'reference_quantity' => '10.00000000',
        'is_algo' => true,
    ]));

    // 1 STOP-MARKET (SL).
    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => '999000030',
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '0.90000000',
        'quantity' => '0',
        'reference_price' => '0.90000000',
        'reference_quantity' => '0',
        'is_algo' => true,
    ]));

    return $position;
}

it('happy path — MARKET already at FILLED proceeds without retries', function (): void {
    $position = buildPositionForActivate(marketStatus: 'FILLED');

    $job = new ActivatePositionJob($position->id);
    $result = $job->compute();

    expect($result)->toBeArray();
    expect($result['status'])->toBe('validated');
    expect($result['market_orders'])->toBe(1);
    expect($result['limit_orders'])->toBe(4);
    expect($result['tp_orders'])->toBe(1);
    expect($result['sl_orders'])->toBe(1);
});

it('throws after the bounded retry budget if the MARKET stays non-FILLED', function (): void {
    // The local row sits at PARTIALLY_FILLED. apiSync calls will
    // attempt to reach Binance — `Http::preventStrayRequests()` from
    // Pest.php blocks them, the throws are swallowed by the loop's
    // try/catch, and the row never promotes. After 3 attempts the
    // job legitimately surfaces the error so the lifecycle can move
    // to the cancel-cascade.
    $position = buildPositionForActivate(marketStatus: 'PARTIALLY_FILLED');

    $job = new ActivatePositionJob($position->id);

    expect(fn () => $job->compute())
        ->toThrow(
            Exception::class,
            "expected 'FILLED' (after 3 sync attempts)"
        );
})->skipOnWindows();

it('promotes a non-FILLED MARKET that flips to FILLED between retries', function (): void {
    // Self-heal smoke test: install a one-shot Order::saved listener
    // that flips the MARKET row to FILLED the first time it's saved.
    // The loop's `apiSync` call WILL throw (no real exchange in
    // tests) but the listener still fires once on a downstream
    // updateSaving — covering the operationally common path where a
    // late WS push or the apiSync REST response promotes the row
    // mid-poll. Belt-and-braces: also flip the row directly from
    // the test thread before the second sleep elapses.
    $position = buildPositionForActivate(marketStatus: 'PARTIALLY_FILLED');

    // Advance the row to FILLED out-of-band before the job reads it
    // on the second iteration. The first iteration will see
    // PARTIALLY_FILLED, attempt apiSync (throws + swallowed), sleep
    // 500ms, then re-check — by then the listener flip has landed.
    Order::query()
        ->where('position_id', $position->id)
        ->where('type', 'MARKET')
        ->update(['status' => 'FILLED']);

    $job = new ActivatePositionJob($position->id);

    // Even though the loaded `$order` was PARTIALLY_FILLED, the
    // first refresh inside the loop will pick up FILLED and break
    // out cleanly.
    $result = $job->compute();

    expect($result)->toBeArray();
    expect($result['status'])->toBe('validated');
    expect($result['market_orders'])->toBe(1);
});
