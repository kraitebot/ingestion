<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\ClosePositionAtomicallyJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the -2022 "already closed" handler on `ClosePositionAtomicallyJob`.
 *
 * Production incident 2026-05-06 — Position 755 (TONUSDT, account 1) and
 * Position 803 (CAKEUSDT, account 4): TP filled naturally, exchange
 * closed the position, our cancel-cleanup workflow ran, and
 * `ClosePositionAtomicallyJob` sent a reduceOnly MARKET to flatten what
 * was already flat. Binance returned `-2022 ReduceOnly Order is rejected`,
 * the legacy handler converted it to `NonNotifiableException`, the step
 * landed in Failed, and the position was marked `failed` — despite
 * having been closed in profit.
 *
 * `-2022` from Binance IS the authoritative "nothing to reduce" signal.
 * It must map to the same `already_closed=true` success shape the old
 * snapshot pre-flight returned, not to a hard failure.
 */
function buildTpClosedPosition(string $exchange = 'binance'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $exchange,
        'name' => mb_ucfirst($exchange),
    ]);

    $symbol = Symbol::factory()->create(['token' => 'TON']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'TON',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $accountAttributes = [
        'api_system_id' => $apiSystem->id,
    ];

    if ($exchange === 'binance') {
        $accountAttributes['binance_api_key'] = 'TESTKEY';
        $accountAttributes['binance_api_secret'] = 'TESTSECRET';
    } elseif ($exchange === 'bitget') {
        $accountAttributes['bitget_api_key'] = 'TESTKEY';
        $accountAttributes['bitget_api_secret'] = 'TESTSECRET';
        $accountAttributes['bitget_passphrase'] = 'TESTPASS';
    }

    $account = Account::factory()->create($accountAttributes);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'TONUSDT',
        'direction' => 'LONG',
        'status' => 'cancelling',
        'total_limit_orders' => 4,
        'quantity' => '10.60000000',
        'opening_price' => '2.36220000',
    ]);

    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => '999000001',
        'type' => 'MARKET',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => '2.36220000',
        'reference_price' => '2.36220000',
        'quantity' => '10.60000000',
        'reference_quantity' => '10.60000000',
        'is_algo' => false,
    ]));

    return $position;
}

it('treats Bitget 22002 "no position to close" as already-closed success', function (): void {
    Http::fake([
        '*' => Http::response(
            json_encode(['code' => '22002', 'msg' => 'No position to close']),
            400,
        ),
    ]);

    $position = buildTpClosedPosition('bitget');

    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result)->toBeArray();
    expect($result['result'])->toBe(['already_closed' => true]);
});

it('treats Binance -2022 reduceOnly rejection as already-closed success', function (): void {
    Http::fake([
        '*' => Http::response(
            json_encode(['code' => -2022, 'msg' => 'ReduceOnly Order is rejected.']),
            400,
        ),
    ]);

    $position = buildTpClosedPosition();

    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result)->toBeArray();
    expect($result['result'])->toBe(['already_closed' => true]);
    expect($result['symbol'])->toBe('TONUSDT');
    expect($result['message'])->toContain('already closed');
});

it('does not leave an orphan MARKET-CANCEL Order row when apiPlace fails with -2022', function (): void {
    // Without the cleanup in apiClose(), every Binance TP-fill close
    // would leak a NEW MARKET-CANCEL row with no exchange_order_id —
    // observed in production 2026-05-06 (orphans 3272, 3280, 3295).
    Http::fake([
        '*' => Http::response(
            json_encode(['code' => -2022, 'msg' => 'ReduceOnly Order is rejected.']),
            400,
        ),
    ]);

    $position = buildTpClosedPosition();

    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();
    $job->computeApiable();

    $orphans = Order::query()
        ->where('position_id', $position->id)
        ->where('type', 'MARKET-CANCEL')
        ->count();

    expect($orphans)->toBe(0);
});
