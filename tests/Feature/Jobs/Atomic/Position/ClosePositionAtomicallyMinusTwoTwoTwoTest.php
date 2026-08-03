<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\ClosePositionAtomicallyJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\Steps;

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

function configurePumpCooldownFixture(Position $position): void
{
    $exchangeSymbol = $position->exchangeSymbol;
    $exchangeSymbol->update([
        'disable_on_price_spike_percentage' => '5',
        'price_spike_cooldown_hours' => 4,
        'tradeable_at' => null,
    ]);
    $exchangeSymbol->storeMarkPriceSnapshot('110');

    $indicator = Indicator::query()->firstOrCreate(
        ['canonical' => 'candle'],
        [
            'type' => 'dashboard',
            'is_active' => true,
            'class' => Kraite\Core\Indicators\History\CandleIndicator::class,
        ],
    );

    IndicatorHistory::create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'indicator_id' => $indicator->id,
        'timeframe' => '1d',
        'timestamp' => (string) now()->timestamp,
        'data' => ['close' => ['99', '100']],
    ]);
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

it('reschedules the second Binance flat check without sleeping a queue worker', function (): void {
    config()->set('kraite.position_safety.flat_confirmation_delay_seconds', 20);
    fakeBinanceCloseRejectionWithPositionSnapshots([]);

    $position = buildTpClosedPosition();

    Steps::usingPrefix('trading', function () use ($position): void {
        $step = Step::create([
            'class' => ClosePositionAtomicallyJob::class,
            'queue' => 'positions',
            'state' => Running::class,
        ]);
        $job = new ClosePositionAtomicallyJob($position->id);
        $job->step = $step;
        $job->assignExceptionHandler();

        $result = $job->computeApiable();
        $fresh = $step->fresh();

        expect($result['confirmation_pending'])->toBeTrue()
            ->and($fresh->state)->toBeInstanceOf(Pending::class)
            ->and((int) now()->diffInSeconds($fresh->dispatch_after))->toBeGreaterThanOrEqual(19)
            ->and($fresh->response)->toHaveKey('binance_flat_confirmation_started_at');
    });

    Sleep::assertNeverSlept();
    Http::assertSentCount(2);
});

it('completes a rescheduled Binance flat confirmation on the second valid snapshot', function (): void {
    fakeBinanceCloseRejectionWithPositionSnapshots([]);

    $position = buildTpClosedPosition();

    Steps::usingPrefix('trading', function () use ($position): void {
        $step = Step::create([
            'class' => ClosePositionAtomicallyJob::class,
            'queue' => 'positions',
            'state' => Running::class,
            'response' => ['binance_flat_confirmation_started_at' => now()->subSeconds(20)->toIso8601String()],
        ]);
        $job = new ClosePositionAtomicallyJob($position->id);
        $job->step = $step;
        $job->assignExceptionHandler();

        $result = $job->computeApiable();

        expect($result['result'])->toBe(['already_closed' => true])
            ->and($step->fresh()->response)->toBeNull();
    });

    Sleep::assertNeverSlept();
    // One request only: the confirmation snapshot. The re-pass no longer
    // fires a redundant close order at the already-flat book (the
    // 2026-07-27/28 error-storm source).
    Http::assertSentCount(1);
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

it('does not stamp pump cooldown when the exchange close fails', function (): void {
    fakeBinanceCloseRejectionWithPositionSnapshots([liveBinancePosition()]);

    $position = buildTpClosedPosition();
    configurePumpCooldownFixture($position);
    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();

    expect(fn () => $job->computeApiable())->toThrow(RequestException::class, '-2022')
        ->and($position->exchangeSymbol->fresh()->tradeable_at)->toBeNull();
});

it('uses the latest daily candle and stamps pump cooldown after a successful idempotent close', function (): void {
    Http::fake([
        '*' => Http::response(
            json_encode(['code' => '22002', 'msg' => 'No position to close']),
            400,
        ),
    ]);

    $position = buildTpClosedPosition('bitget');
    configurePumpCooldownFixture($position);
    $job = new ClosePositionAtomicallyJob($position->id);
    $job->assignExceptionHandler();

    $result = $job->computeApiable();

    expect($result['pump_cooldown_triggered'])->toBeTrue()
        ->and($result['cooldown_details']['change_percent'])->toBe('10.0000000000000000')
        ->and($position->exchangeSymbol->fresh()->tradeable_at)->not->toBeNull();
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

it('does not place another close order on a rescheduled flat-confirmation pass', function (): void {
    // The 2026-07-27/28 error storms: every 20-second confirmation
    // re-pass ran "always attempt the close first" again, firing another
    // doomed reduceOnly MARKET at an already-flat book. 15+ of those
    // rejections inside 20 minutes tripped the exchange_error_storm
    // monitor twice in 32 hours — both false alarms manufactured by our
    // own retries. The re-pass must check its pending confirmation
    // FIRST and only re-attempt the close when the position turns out
    // to still be live.
    fakeBinanceCloseRejectionWithPositionSnapshots([]);

    $position = buildTpClosedPosition();

    Steps::usingPrefix('trading', function () use ($position): void {
        $step = Step::create([
            'class' => ClosePositionAtomicallyJob::class,
            'queue' => 'positions',
            'state' => Running::class,
            'response' => ['binance_flat_confirmation_started_at' => now()->subSeconds(20)->toIso8601String()],
        ]);
        $job = new ClosePositionAtomicallyJob($position->id);
        $job->step = $step;
        $job->assignExceptionHandler();

        $result = $job->computeApiable();

        expect($result['result'])->toBe(['already_closed' => true]);
    });

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/fapi/v1/order');
    });
    Http::assertSentCount(1);
});

it('re-attempts the close when the pending confirmation finds the position live again', function (): void {
    // Safety direction of the same fix: if the flat snapshot was a lag
    // artefact and the position is still on the book at re-pass time,
    // the close MUST still be sent — the no-redundant-order optimisation
    // can never leave real exposure unclosed.
    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response(json_encode([liveBinancePosition()]), 200),
        '*/fapi/v1/order*' => Http::response(
            json_encode(['code' => -2022, 'msg' => 'ReduceOnly Order is rejected.']),
            400,
        ),
    ]);

    $position = buildTpClosedPosition();

    Steps::usingPrefix('trading', function () use ($position): void {
        $step = Step::create([
            'class' => ClosePositionAtomicallyJob::class,
            'queue' => 'positions',
            'state' => Running::class,
            'response' => ['binance_flat_confirmation_started_at' => now()->subSeconds(20)->toIso8601String()],
        ]);
        $job = new ClosePositionAtomicallyJob($position->id);
        $job->step = $step;
        $job->assignExceptionHandler();

        try {
            $job->computeApiable();
        } catch (Throwable) {
            // The live-position close still rejects with -2022 in this fixture;
            // the point under test is only that the order WAS attempted.
        }
    });

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/fapi/v1/order');
    });
});
