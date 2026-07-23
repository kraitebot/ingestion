<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\ClosePositionAtomicallyJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the -2022 reconciliation handler on `ClosePositionAtomicallyJob`.
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
 * Binance documents `-2022` as an open-order conflict, not authoritative
 * proof that the position is flat. The close may be treated as idempotent
 * only after two valid account-position reads confirm zero exposure outside
 * the known REST lag window.
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
        $accountAttributes['on_hedge_mode'] = true;
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

function fakeBinanceCloseRejectionWithPositionSnapshots(array ...$snapshots): void
{
    $positionsSequence = Http::sequence();

    foreach ($snapshots as $snapshot) {
        $positionsSequence->push($snapshot);
    }

    Http::fake([
        '*/fapi/v1/order*' => Http::response(
            json_encode(['code' => -2022, 'msg' => 'ReduceOnly Order is rejected.']),
            400,
        ),
        '*/fapi/v3/positionRisk*' => $positionsSequence,
    ]);
}

function liveBinancePosition(string $symbol = 'TONUSDT'): array
{
    return [
        'symbol' => $symbol,
        'positionSide' => 'LONG',
        'positionAmt' => '10.60000000',
    ];
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

it('treats Bitget UTA 25227 "no position available to close" as already-closed success', function (): void {
    Http::fake([
        '*' => Http::response(
            json_encode(['code' => '25227', 'msg' => 'No position available to close']),
            400,
        ),
    ]);

    $position = buildTpClosedPosition('bitget');
    $position->account->updateSaving([
        'bitget_account_mode' => 'unified',
        'on_hedge_mode' => true,
    ]);

    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result)->toBeArray()
        ->and($result['result'])->toBe(['already_closed' => true]);
    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/v3/trade/close-positions',
    ));
});

it('treats Binance -2022 as already closed only after two valid flat snapshots', function (): void {
    config()->set('kraite.position_safety.flat_confirmation_delay_seconds', 20);
    fakeBinanceCloseRejectionWithPositionSnapshots([], []);

    $position = buildTpClosedPosition();
    expect($position->status)->toBe('cancelling');

    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($result)->toBeArray();
    expect($result['result'])->toBe(['already_closed' => true]);
    expect($result['symbol'])->toBe('TONUSDT');
    expect($result['message'])->toContain('confirmed flat');
    expect($position->refresh()->status)->toBe('cancelling');

    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(20)->seconds(),
    ]);
});

it('does not treat Binance -2022 as success while the exact position remains open', function (): void {
    fakeBinanceCloseRejectionWithPositionSnapshots([
        liveBinancePosition(),
    ]);

    $position = buildTpClosedPosition();
    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();

    expect(fn () => $job->computeApiable())
        ->toThrow(RequestException::class, '-2022')
        ->and($position->refresh()->status)->toBe('cancelling');

    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('does not treat Binance -2022 as success when the position reappears after the lag window', function (): void {
    config()->set('kraite.position_safety.flat_confirmation_delay_seconds', 20);
    fakeBinanceCloseRejectionWithPositionSnapshots(
        [],
        [liveBinancePosition()],
    );

    $position = buildTpClosedPosition();
    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();

    expect(fn () => $job->computeApiable())
        ->toThrow(RequestException::class, '-2022')
        ->and($position->refresh()->status)->toBe('cancelling');

    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(20)->seconds(),
    ]);
});

it('does not treat Binance -2022 as success when position confirmation is malformed', function (): void {
    fakeBinanceCloseRejectionWithPositionSnapshots([[
        'symbol' => 'TONUSDT',
        'positionSide' => 'UNKNOWN',
        'positionAmt' => '10.60000000',
    ]]);

    $position = buildTpClosedPosition();
    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();

    expect(fn () => $job->computeApiable())
        ->toThrow(RequestException::class, '-2022')
        ->and($position->refresh()->status)->toBe('cancelling');

    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('does not leave an orphan MARKET-CANCEL Order row when apiPlace fails with -2022', function (): void {
    // Without the cleanup in apiClose(), every Binance TP-fill close
    // would leak a NEW MARKET-CANCEL row with no exchange_order_id —
    // observed in production 2026-05-06 (orphans 3272, 3280, 3295).
    fakeBinanceCloseRejectionWithPositionSnapshots([
        liveBinancePosition(),
    ]);

    $position = buildTpClosedPosition();

    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();
    expect(fn () => $job->computeApiable())->toThrow(RequestException::class, '-2022');

    $orphans = Order::query()
        ->where('position_id', $position->id)
        ->where('type', 'MARKET-CANCEL')
        ->count();

    expect($orphans)->toBe(0);
});
