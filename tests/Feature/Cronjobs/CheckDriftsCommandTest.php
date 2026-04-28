<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareSyncOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\PrepareCancelOrphanOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Drift\DriftChecker;
use Mockery as M;
use StepDispatcher\Models\Step;

uses(RefreshDatabase::class)->group('feature', 'drift', 'cron');

beforeEach(function () {
    // Engine admin row required by Kraite::admin() inside NotificationService.
    // Some migrations pre-seed id=1, so updateOrCreate keeps both paths safe.
    Kraite::updateOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'test_key',
            'admin_pushover_application_key' => 'test_app_key',
            'notification_channels' => ['mail', 'pushover'],
        ],
    );

    // phpunit.xml disables notifications globally for tests — flip it on
    // for the spotter suite so we can verify the dispatch end-to-end.
    config(['kraite.notifications_enabled' => true]);

    Notification::fake();
});

afterEach(function () {
    M::close();
});

/**
 * Builds the standard fixture: one active LONG position on Binance with
 * one FILLED MARKET entry order at $1.00 / qty=10. The position's
 * orders are intentionally aged outside the spotter's quiet window so
 * any drift the test injects passes the 10-minute filter.
 *
 * @return array{account: Account, position: Position, pair: string}
 */
function makeSpotterFixture(string $token = 'SPOT', string $status = 'active'): array
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
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
        'leverage' => 10,
    ]);

    return ['account' => $account, 'position' => $position, 'pair' => $token.'USDT'];
}

function ageAllOrdersOutsideQuietWindow(int $positionId): void
{
    Order::where('position_id', $positionId)->update([
        'updated_at' => Carbon::now()->subMinutes(20),
    ]);
}

function makeSpotterOrder(int $positionId, array $overrides = []): Order
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

/**
 * Stub the DriftCheckService so the spotter operates on a canned
 * report. This isolates the command's orchestration logic from the
 * real exchange-fetch path (which has its own focused unit tests).
 */
function bindDriftReport(\Kraite\Core\Support\Drift\AccountDriftReport $report): void
{
    $mock = M::mock(DriftChecker::class);
    $mock->shouldReceive('analyseAccount')->andReturn($report);
    app()->instance(DriftChecker::class, $mock);
}

it('dispatches PrepareSyncOrdersJob and notifies on a quiet drifted active position', function () {
    $f = makeSpotterFixture();
    $position = $f['position'];

    makeSpotterOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    $tp = makeSpotterOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    ageAllOrdersOutsideQuietWindow($position->id);

    // Stub the analyseAccount call with a drift-report shaped to mirror
    // a missed-WAP scenario: PROFIT-LIMIT price is wrong on our side.
    $report = new \Kraite\Core\Support\Drift\AccountDriftReport(
        account: $f['account'],
        positions: [
            new \Kraite\Core\Support\Drift\PositionDriftReport(
                symbol: $f['pair'],
                direction: 'LONG',
                status: \Kraite\Core\Support\Drift\PositionDriftReport::STATUS_DRIFT,
                positionId: $position->id,
                db: ['id' => $position->id, 'status' => 'active'],
                exchange: ['quantity' => '10'],
                positionDriftFields: [],
                orders: [
                    new \Kraite\Core\Support\Drift\OrderDriftReport(
                        status: \Kraite\Core\Support\Drift\OrderDriftReport::STATUS_DRIFT,
                        db: ['id' => $tp->id, 'type' => 'PROFIT-LIMIT', 'price' => '1.10000000'],
                        exchange: ['type' => 'LIMIT', 'price' => '1.07500000'],
                        driftFields: ['price'],
                    ),
                ],
            ),
        ],
        orphanOrders: [],
    );
    bindDriftReport($report);

    $exit = $this->artisan('kraite:cron-check-drifts')->run();

    expect($exit)->toBe(0);

    $syncSteps = Step::where('class', PrepareSyncOrdersJob::class)->get();
    expect($syncSteps)->toHaveCount(1);
    expect($syncSteps->first()->arguments['positionId'])->toBe($position->id);

    Notification::assertCount(1);
    Notification::assertSentTo(Kraite::admin(), AlertNotification::class);
});

it('catches the WAP scenario end-to-end: PROFIT-LIMIT price drift on a quiet position', function () {
    // Bruno's flagship test. Most-feared incident: a DCA fill triggers a
    // WAP recalc, the new TP price hits the exchange but the DB write
    // never lands. The spotter must catch the price disagreement, fire
    // exactly one pushover, and dispatch sync-orders.
    $f = makeSpotterFixture(token: 'WAP');
    $position = $f['position'];

    // Baseline orders: filled entry + a TP whose DB price is stale.
    makeSpotterOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    $tp = makeSpotterOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    ageAllOrdersOutsideQuietWindow($position->id);

    // Drift-report carries the live exchange-side WAP'd price (1.075)
    // — well outside the 0.1% tolerance band, so the heal must dispatch.
    $report = new \Kraite\Core\Support\Drift\AccountDriftReport(
        account: $f['account'],
        positions: [
            new \Kraite\Core\Support\Drift\PositionDriftReport(
                symbol: $f['pair'],
                direction: 'LONG',
                status: \Kraite\Core\Support\Drift\PositionDriftReport::STATUS_DRIFT,
                positionId: $position->id,
                db: ['id' => $position->id, 'status' => 'active'],
                exchange: ['quantity' => '10'],
                positionDriftFields: [],
                orders: [
                    new \Kraite\Core\Support\Drift\OrderDriftReport(
                        status: \Kraite\Core\Support\Drift\OrderDriftReport::STATUS_DRIFT,
                        db: ['id' => $tp->id, 'type' => 'PROFIT-LIMIT', 'price' => '1.10000000'],
                        exchange: ['type' => 'LIMIT', 'price' => '1.07500000'],
                        driftFields: ['price'],
                    ),
                ],
            ),
        ],
        orphanOrders: [],
    );
    bindDriftReport($report);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    // Heal dispatched
    expect(Step::where('class', PrepareSyncOrdersJob::class)->count())->toBe(1);

    // Pushover (admin only) fired exactly once. We assert via the
    // notifiable-less form because Kraite::admin() returns a virtual
    // User whose identity in the fake is matched by class only.
    Notification::assertCount(1);
    Notification::assertSentTo(Kraite::admin(), AlertNotification::class);
});

it('skips an active position when one of its orders was touched within the quiet window', function () {
    $f = makeSpotterFixture(token: 'QUIET');
    $position = $f['position'];

    makeSpotterOrder($position->id, [
        'type' => 'MARKET', 'status' => 'FILLED',
        'price' => '1.00000000', 'quantity' => '10.00000000',
    ]);
    makeSpotterOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    // Push everything outside the window first, then touch one back in.
    ageAllOrdersOutsideQuietWindow($position->id);
    Order::where('position_id', $position->id)->limit(1)->update([
        'updated_at' => Carbon::now()->subMinutes(2),
    ]);

    // Bind a no-op service — even if it returns drift, the position
    // should be filtered out before analyse runs.
    $mock = M::mock(DriftChecker::class);
    $mock->shouldNotReceive('analyseAccount');
    app()->instance(DriftChecker::class, $mock);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    expect(Step::where('class', PrepareSyncOrdersJob::class)->count())->toBe(0);
    Notification::assertNothingSent();
});

it('never audits positions in mid-flight statuses (syncing, waping, closing, opening, new)', function () {
    foreach (['syncing', 'waping', 'closing', 'opening', 'new'] as $status) {
        $f = makeSpotterFixture(token: 'MID', status: $status);
        makeSpotterOrder($f['position']->id, [
            'type' => 'MARKET', 'status' => 'FILLED',
            'price' => '1.00000000', 'quantity' => '10.00000000',
        ]);
        ageAllOrdersOutsideQuietWindow($f['position']->id);
    }

    $mock = M::mock(DriftChecker::class);
    $mock->shouldNotReceive('analyseAccount');
    app()->instance(DriftChecker::class, $mock);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    expect(Step::where('class', PrepareSyncOrdersJob::class)->count())->toBe(0);
    Notification::assertNothingSent();
});

it('dispatches one PrepareCancelOrphanOrders per orphan parent (covers algo + non-algo orders)', function () {
    // Phase 3A: spotter delegates to the existing close-workflow cancel
    // machinery. ONE Step per orphan parent, regardless of how many
    // individual orders that parent carries — the lifecycle behind it
    // handles bulk-cancel for regular orders + per-order cancel for
    // algo orders. Reuses the same code path the close workflow already
    // uses in production.
    $f = makeSpotterFixture(token: 'ORPHAN', status: 'closed');
    $position = $f['position'];

    makeSpotterOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);
    makeSpotterOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);
    // A regular LIMIT — Phase 1 dispatched nothing for these; Phase 3A
    // covers them via the bulk-cancel atomic the lifecycle spawns.
    makeSpotterOrder($position->id, [
        'type' => 'LIMIT', 'side' => 'BUY', 'status' => 'NEW',
        'price' => '0.95000000', 'quantity' => '10.00000000', 'is_algo' => false,
    ]);

    ageAllOrdersOutsideQuietWindow($position->id);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    $cancelSteps = Step::where('class', PrepareCancelOrphanOrdersJob::class)->get();
    expect($cancelSteps)->toHaveCount(1);
    expect($cancelSteps->first()->arguments['positionId'])->toBe($position->id);

    // ONE summary pushover for the parent position.
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('skips orphan cancellation when one of the orphan orders was touched recently', function () {
    $f = makeSpotterFixture(token: 'RACE', status: 'cancelled');
    $position = $f['position'];

    $o1 = makeSpotterOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);
    makeSpotterOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    // Age both, then poke one back inside the quiet window.
    ageAllOrdersOutsideQuietWindow($position->id);
    Order::where('id', $o1->id)->update(['updated_at' => Carbon::now()->subMinutes(2)]);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    expect(Step::where('class', PrepareCancelOrphanOrdersJob::class)->count())->toBe(0);
    Notification::assertNothingSent();
});

it('marks ghost orphans CANCELLED in DB inline, dispatches no cancel job, still notifies', function () {
    // Ghost order: never reached the exchange (no exchange_order_id).
    // apiCancel has nothing to act on. The spotter goes beyond pure
    // detection: it UPDATEs status='CANCELLED' in DB inline so the
    // notification stops re-firing forever. One pushover surfaces what
    // was done.
    $f = makeSpotterFixture(token: 'GHOST', status: 'failed');
    $position = $f['position'];

    $ghost = makeSpotterOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'quantity' => '10.00000000',
        'is_algo' => true,
        'exchange_order_id' => null, // Ghost — never made it to Binance.
    ]);

    ageAllOrdersOutsideQuietWindow($position->id);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    // No cancel job dispatched (no api id to act on).
    expect(Step::where('class', PrepareCancelOrphanOrdersJob::class)->count())->toBe(0);
    // DB row reconciled inline.
    expect(Order::find($ghost->id)->status)->toBe('CANCELLED');
    // One operator pushover summarising the action.
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('handles a mixed orphan position: ghost gets DB-cancelled, real ones get the lifecycle dispatched, one notification for both', function () {
    // Real-world shape (BSBUSDT / LABUSDT on prod): same parent carries
    // a STOP-MARKET ghost (algo, no exchange_order_id) AND real orders
    // that DID reach the exchange. The spotter handles both tracks on
    // a single pass: ghost is reconciled inline in DB, real orders are
    // delegated to the cancel-orphan-orders lifecycle. One pushover.
    $f = makeSpotterFixture(token: 'MIXED', status: 'failed');
    $position = $f['position'];

    $ghost = makeSpotterOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'quantity' => '10.00000000',
        'is_algo' => true,
        'exchange_order_id' => null,
    ]);
    $real = makeSpotterOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.91000000', 'quantity' => '10.00000000',
        'is_algo' => true,
        'exchange_order_id' => '1234567890', // Reached the exchange.
    ]);

    ageAllOrdersOutsideQuietWindow($position->id);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    // Ghost: marked CANCELLED inline.
    expect(Order::find($ghost->id)->status)->toBe('CANCELLED');
    // Real algo: dispatched for exchange-side cancel via the lifecycle.
    $cancelSteps = Step::where('class', PrepareCancelOrphanOrdersJob::class)->get();
    expect($cancelSteps)->toHaveCount(1);
    expect($cancelSteps->first()->arguments['positionId'])->toBe($position->id);
    expect(Order::find($real->id)->status)->toBe('NEW'); // The cancel lifecycle, not the spotter, flips this once the exchange call completes.
    // One pushover per parent — covers both tracks.
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('treats failed positions the same as closed for orphan cleanup', function () {
    $f = makeSpotterFixture(token: 'FAIL', status: 'failed');
    $position = $f['position'];

    makeSpotterOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'quantity' => '10.00000000', 'is_algo' => true,
    ]);

    ageAllOrdersOutsideQuietWindow($position->id);

    $this->artisan('kraite:cron-check-drifts')->assertSuccessful();

    expect(Step::where('class', PrepareCancelOrphanOrdersJob::class)->count())->toBe(1);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});
