<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Drift\AccountDriftReport;
use Kraite\Core\Support\Drift\DriftChecker;
use Mockery as M;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

uses(RefreshDatabase::class)->group('feature', 'drift', 'cron', 'wap');

/**
 * Scope 2b — WAP under-application self-heal on kraite:cron-check-drifts.
 *
 * Pins the 2026-07-13 FILUSDT incident (position #394): a DCA LIMIT fill
 * landed on Binance, the WAP take-profit resize crashed mid-flight
 * (missing `avgPrice` in the modify response), the correction loop
 * reverted the exchange-side resize, and the position sat `active` with
 * a TP covering 47.3 against a 141.9 exchange position — permanently,
 * because the failed steps never retry and the quiet-window drift scope
 * never inspects busy positions. Scope 2b must find that exact state and
 * re-dispatch ApplyWapJob.
 */
beforeEach(function (): void {
    Kraite::updateOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'test_key',
            'admin_pushover_application_key' => 'test_app_key',
            'notification_channels' => ['mail', 'pushover'],
        ],
    );

    config(['kraite.notifications_enabled' => true]);

    Notification::fake();

    // Neutralise Scope 1's exchange-facing drift analysis — this suite
    // exercises the DB-only Scope 2b exclusively.
    $mock = M::mock(DriftChecker::class);
    $mock->shouldReceive('analyseAccount')->andReturnUsing(function (Account $account): AccountDriftReport {
        return new AccountDriftReport(account: $account, positions: [], orphanOrders: []);
    });
    app()->instance(DriftChecker::class, $mock);
});

afterEach(function (): void {
    M::close();
});

/**
 * The FILUSDT-shaped fixture: one active LONG on Binance with a FILLED
 * MARKET entry (47.3 @ 0.8114), one FILLED DCA LIMIT (94.6 @ 0.7424),
 * a resting NEW take-profit still sized for the entry only (47.3), and
 * a resting stop-loss. quantity_precision=1 matches FIL's step size.
 *
 * @return array{account: Account, position: Position, tp: Order}
 */
function makeWapHealFixture(string $token = 'WPH', string $status = 'active', string $tpQuantity = '47.30000000'): array
{
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
        'quantity_precision' => 1,
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
        'opening_price' => '0.81140000',
        'quantity' => '47.30000000',
        'total_limit_orders' => 4,
        'leverage' => 20,
        'profit_percentage' => '0.350',
    ]);

    makeWapHealOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '0.81140000', 'quantity' => '47.30000000',
    ]);
    makeWapHealOrder($position->id, [
        'type' => 'LIMIT', 'status' => 'FILLED',
        'price' => '0.74240000', 'quantity' => '94.60000000',
    ]);
    $tp = makeWapHealOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.81430000', 'quantity' => $tpQuantity, 'is_algo' => false,
    ]);
    makeWapHealOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.51940000', 'quantity' => '47.30000000', 'is_algo' => true,
    ]);

    return ['account' => $account, 'position' => $position, 'tp' => $tp];
}

function makeWapHealOrder(int $positionId, array $overrides = []): Order
{
    return Order::withoutEvents(function () use ($positionId, $overrides): Order {
        return Order::create(array_merge([
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
        ], $overrides));
    });
}

function wapHealStepsFor(int $positionId): Illuminate\Support\Collection
{
    return Steps::usingPrefix('trading', function () use ($positionId) {
        return Step::query()
            ->where('class', ApplyWapJob::class)
            ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$positionId])
            ->get();
    });
}

function makeWapHealStep(int $positionId, string $state): Step
{
    return Steps::usingPrefix('trading', function () use ($positionId, $state): Step {
        $step = Step::create([
            'class' => ApplyWapJob::class,
            'queue' => 'positions',
            'arguments' => ['positionId' => $positionId],
        ]);

        // Step::create always starts Pending — force non-default states
        // directly so the dedupe query sees them.
        if ($state !== Pending::class) {
            Step::query()->whereKey($step->id)->update(['state' => $state]);
        }

        return $step->fresh();
    });
}

function runWapHealPass(): void
{
    test()->artisan('kraite:cron-check-drifts', [
        '--skip-structure-audit' => true,
        '--skip-engine-health' => true,
    ])->assertSuccessful();
}

it('re-dispatches ApplyWapJob for the FILUSDT-shaped stuck WAP and notifies once', function (): void {
    $f = makeWapHealFixture();
    $position = $f['position'];

    // Before: no heal step exists.
    expect(wapHealStepsFor($position->id))->toHaveCount(0);

    runWapHealPass();

    // After: exactly one Pending ApplyWapJob for this position at high
    // priority — the observer's dispatch shape. The queue string itself
    // is owned by the step-queue transformer (priority-lane rewrite), so
    // it is deliberately not pinned here.
    $steps = wapHealStepsFor($position->id);
    expect($steps)->toHaveCount(1);
    expect($steps->first()->state)->toBeInstanceOf(Pending::class)
        ->and($steps->first()->priority)->toBe('high')
        ->and((int) $steps->first()->arguments['positionId'])->toBe($position->id);

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('does not heal a position in syncing status', function (): void {
    $f = makeWapHealFixture(token: 'SYN', status: 'syncing');

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('does not heal a position in waping status', function (): void {
    $f = makeWapHealFixture(token: 'WPG', status: 'waping');

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('does not heal when the take-profit already covers the filled ladder', function (): void {
    // TP resized to the full 141.9 — the WAP applied fine.
    $f = makeWapHealFixture(token: 'COV', tpQuantity: '141.90000000');

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('does not heal when the take-profit is no longer resting NEW', function (): void {
    foreach (['PARTIALLY_FILLED', 'FILLED'] as $tpStatus) {
        $f = makeWapHealFixture(token: 'EXI');
        Order::withoutEvents(function () use ($f, $tpStatus): void {
            $f['tp']->forceFill(['status' => $tpStatus])->save();
        });

        runWapHealPass();

        expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    }

    Notification::assertNothingSent();
});

it('does not stack a second heal while an ApplyWapJob step is still pending', function (): void {
    $f = makeWapHealFixture(token: 'DUP');
    makeWapHealStep($f['position']->id, Pending::class);

    runWapHealPass();

    // Still just the pre-existing step — no duplicate, no notification.
    expect(wapHealStepsFor($f['position']->id))->toHaveCount(1);
    Notification::assertNothingSent();
});

it('does not stack a second heal while an ApplyWapJob step is dispatched', function (): void {
    $f = makeWapHealFixture(token: 'DIS');
    makeWapHealStep($f['position']->id, Dispatched::class);

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(1);
    Notification::assertNothingSent();
});

it('heals again after a prior ApplyWapJob failed terminally — the FIL regression', function (): void {
    // The incident state: observer-dispatched WAP crashed into Failed.
    // Terminal states must NOT block the heal — they ARE the wound.
    $f = makeWapHealFixture(token: 'FLD');
    $failed = makeWapHealStep($f['position']->id, Failed::class);

    runWapHealPass();

    $steps = wapHealStepsFor($f['position']->id);
    expect($steps)->toHaveCount(2);

    $fresh = $steps->firstWhere('id', '!=', $failed->id);
    expect($fresh->state)->toBeInstanceOf(Pending::class);

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('tolerates quantity-precision truncation without a false heal', function (): void {
    // Fills sum to 141.94, but FIL's 0.1 step size means the exchange TP
    // can only ever carry 141.9 — that is full coverage, not drift.
    $f = makeWapHealFixture(token: 'PRC', tpQuantity: '141.90000000');
    makeWapHealOrder($f['position']->id, [
        'type' => 'LIMIT', 'status' => 'FILLED',
        'price' => '0.70000000', 'quantity' => '0.04000000',
    ]);

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('ignores unfilled LIMIT ladder rungs when computing coverage', function (): void {
    // Only the MARKET entry has filled; the resting NEW LIMITs must not
    // count toward the expected quantity.
    $f = makeWapHealFixture(token: 'NEWL', tpQuantity: '47.30000000');
    Order::withoutEvents(function () use ($f): void {
        Order::where('position_id', $f['position']->id)
            ->where('type', 'LIMIT')
            ->update(['status' => 'NEW']);
    });
    makeWapHealOrder($f['position']->id, [
        'type' => 'LIMIT', 'status' => 'NEW',
        'price' => '0.67340000', 'quantity' => '189.20000000',
    ]);

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('does not treat a mis-sized TP with no DCA fill as a WAP case', function (): void {
    // Under-covered TP but zero FILLED LIMITs — a different anomaly,
    // outside this scope's mandate.
    $f = makeWapHealFixture(token: 'NOL', tpQuantity: '40.00000000');
    Order::withoutEvents(function () use ($f): void {
        Order::where('position_id', $f['position']->id)
            ->where('type', 'LIMIT')
            ->update(['status' => 'NEW']);
    });

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('heals only the stuck position and leaves the healthy sibling untouched', function (): void {
    $stuck = makeWapHealFixture(token: 'STK');
    $healthy = makeWapHealFixture(token: 'HLT', tpQuantity: '141.90000000');

    runWapHealPass();

    expect(wapHealStepsFor($stuck['position']->id))->toHaveCount(1);
    expect(wapHealStepsFor($healthy['position']->id))->toHaveCount(0);

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('skips the whole scope when --skip-wap-heal is passed', function (): void {
    $f = makeWapHealFixture(token: 'SKP');

    test()->artisan('kraite:cron-check-drifts', [
        '--skip-structure-audit' => true,
        '--skip-engine-health' => true,
        '--skip-wap-heal' => true,
    ])->assertSuccessful();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('still heals while the bot is cooled (opening halted)', function (): void {
    // Cooldown halts NEW opens; existing positions keep trading and must
    // stay correctly protected — the heal runs before the cooled latch.
    Kraite::query()->whereKey(1)->update(['allow_opening_positions' => false]);

    $f = makeWapHealFixture(token: 'CLD');

    runWapHealPass();

    expect(wapHealStepsFor($f['position']->id))->toHaveCount(1);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('respects the --account_id filter', function (): void {
    $inScope = makeWapHealFixture(token: 'ACA');
    $outOfScope = makeWapHealFixture(token: 'ACB');

    test()->artisan('kraite:cron-check-drifts', [
        '--skip-structure-audit' => true,
        '--skip-engine-health' => true,
        '--account_id' => $inScope['account']->id,
    ])->assertSuccessful();

    expect(wapHealStepsFor($inScope['position']->id))->toHaveCount(1);
    expect(wapHealStepsFor($outOfScope['position']->id))->toHaveCount(0);

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});
