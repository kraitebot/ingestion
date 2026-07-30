<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDefinition;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Notifications\Channels\AppPushChannel;
use Kraite\Core\Support\Drift\AccountDriftReport;
use Kraite\Core\Support\Drift\DriftChecker;
use Kraite\Core\Support\Health\Remediation\TradingCooldown;
use Mockery as M;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\Support\Steps;

uses(RefreshDatabase::class)->group('feature', 'drift', 'cron', 'structure');

beforeEach(function (): void {
    $this->monitoringResourceLock = acquireKraiteTestLock('shared-monitoring-directory');

    Kraite::updateOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'test_key',
            'admin_pushover_application_key' => 'test_app_key',
            'notification_channels' => ['mail', 'pushover'],
            'allow_opening_positions' => true,
        ],
    );

    config(['kraite.notifications_enabled' => true]);

    // Belt-and-braces: even if a quiet position slips into the drift
    // audit, the mocked checker returns an empty report so the new
    // structure scope is the only thing that can fire notifications.
    $mock = M::mock(DriftChecker::class);
    $mock->shouldReceive('analyseAccount')->andReturnUsing(function (Account $account) {
        return new AccountDriftReport(account: $account, positions: [], orphanOrders: []);
    });
    app()->instance(DriftChecker::class, $mock);

    // Clear any stale notification throttle keys, then restore the IP
    // cache the global Pest preamble seeded so NotificationMessageBuilder
    // doesn't try to hit ipify under preventStrayRequests().
    Cache::flush();
    seedKraiteServerIpCache();

    Notification::fake();
});

afterEach(function (): void {
    releaseKraiteTestLock($this->monitoringResourceLock ?? null);
    M::close();
});

/**
 * Builds a minimal active position with a configurable expected limit
 * order count. Caller injects whichever combination of orders they want
 * to exercise the structure auditor.
 *
 * @return array{account: Account, position: Position, pair: string}
 */
function makeStructureFixture(int $totalLimitOrders = 4, string $token = 'STRUCT'): array
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
        'status' => 'active',
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
        'leverage' => 10,
        'total_limit_orders' => $totalLimitOrders,
    ]);

    return ['account' => $account, 'position' => $position, 'pair' => $token.'USDT'];
}

function makeStructureOrder(int $positionId, array $overrides = []): Order
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
 * Inserts the full healthy structure for an active LONG position:
 *   - 1 MARKET FILLED entry
 *   - $totalLimits LIMIT NEW rungs
 *   - 1 PROFIT-LIMIT NEW take profit
 *   - 1 STOP-MARKET NEW stop loss
 */
function seedHealthyStructure(int $positionId, int $totalLimits): void
{
    makeStructureOrder($positionId, [
        'type' => 'MARKET', 'status' => 'FILLED',
    ]);

    for ($i = 0; $i < $totalLimits; $i++) {
        makeStructureOrder($positionId, [
            'type' => 'LIMIT', 'status' => 'NEW',
            'price' => sprintf('%.8f', 0.99 - ($i * 0.01)),
        ]);
    }

    makeStructureOrder($positionId, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'is_algo' => true,
    ]);

    makeStructureOrder($positionId, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'is_algo' => true,
    ]);
}

it('does not notify when the active position has its full structure intact', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4);
    seedHealthyStructure($f['position']->id, 4);

    $exit = $this->artisan('kraite:cron-check-drifts')->run();

    expect($exit)->toBe(0);
    Notification::assertNothingSent();
    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();
});

it('notifies and halts opens when the take profit is missing on an active position', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4);
    $position = $f['position'];

    makeStructureOrder($position->id, ['type' => 'MARKET', 'status' => 'FILLED']);
    for ($i = 0; $i < 4; $i++) {
        makeStructureOrder($position->id, ['type' => 'LIMIT', 'status' => 'NEW']);
    }
    makeStructureOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'is_algo' => true,
    ]);
    // No PROFIT-* row — this is the original real-world incident.

    $exit = $this->artisan('kraite:cron-check-drifts')->run();

    expect($exit)->toBe(0);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    Notification::assertSentTo(Kraite::admin(), AlertNotification::class);
    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
});

it('treats a CANCELLED stop-loss as missing and halts opens', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4);
    $position = $f['position'];

    makeStructureOrder($position->id, ['type' => 'MARKET', 'status' => 'FILLED']);
    for ($i = 0; $i < 4; $i++) {
        makeStructureOrder($position->id, ['type' => 'LIMIT', 'status' => 'NEW']);
    }
    makeStructureOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'is_algo' => true,
    ]);
    makeStructureOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'CANCELLED',
        'price' => '0.90000000', 'is_algo' => true,
    ]);

    $exit = $this->artisan('kraite:cron-check-drifts')->run();

    expect($exit)->toBe(0);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
});

it('flags incomplete limit-order count when fewer live limits exist than total_limit_orders promised', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4);
    $position = $f['position'];

    makeStructureOrder($position->id, ['type' => 'MARKET', 'status' => 'FILLED']);
    // Only 3 NEW limits + 1 CANCELLED — should report 3/4 live.
    for ($i = 0; $i < 3; $i++) {
        makeStructureOrder($position->id, ['type' => 'LIMIT', 'status' => 'NEW']);
    }
    makeStructureOrder($position->id, ['type' => 'LIMIT', 'status' => 'CANCELLED']);
    makeStructureOrder($position->id, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'is_algo' => true,
    ]);
    makeStructureOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'is_algo' => true,
    ]);

    $exit = $this->artisan('kraite:cron-check-drifts')->run();

    expect($exit)->toBe(0);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
});

it('does not halt opens while a live order-replacement workflow is repairing the missing structure', function (string $replacementClass): void {
    $f = makeStructureFixture(totalLimitOrders: 4, token: 'REPAIR');
    seedHealthyStructure($f['position']->id, 3);

    Step::prefix('trading')->create([
        'class' => $replacementClass,
        'queue' => 'priority',
        'relatable_type' => $f['position']->getMorphClass(),
        'relatable_id' => $f['position']->id,
        'arguments' => ['positionId' => $f['position']->id],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);

    $this->artisan('kraite:cron-check-drifts')->run();

    Notification::assertNothingSent();
    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();
})->with([
    'replacement orchestrator' => [PreparePositionReplacementJob::class],
    'replacement atomic step' => [RecreateCancelledOrderJob::class],
]);

it('still halts opens when the replacement workflow is terminal and the structure remains broken', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4, token: 'REPAIRFAILED');
    seedHealthyStructure($f['position']->id, 3);

    Step::prefix('trading')->create([
        'class' => PreparePositionReplacementJob::class,
        'state' => Completed::class,
        'queue' => 'priority',
        'relatable_type' => $f['position']->getMorphClass(),
        'relatable_id' => $f['position']->id,
        'arguments' => ['positionId' => $f['position']->id],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);

    $this->artisan('kraite:cron-check-drifts')->run();

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
});

it('skips positions that are not in active status', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4);
    $f['position']->update(['status' => 'opening']);

    // Position is broken (no orders at all) but it's in `opening`, not `active` — must be skipped.
    $exit = $this->artisan('kraite:cron-check-drifts')->run();

    expect($exit)->toBe(0);
    Notification::assertNothingSent();
    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();
});

it('throttles repeated detections of the same broken position to a single notification', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4);
    $position = $f['position'];

    makeStructureOrder($position->id, ['type' => 'MARKET', 'status' => 'FILLED']);
    for ($i = 0; $i < 4; $i++) {
        makeStructureOrder($position->id, ['type' => 'LIMIT', 'status' => 'NEW']);
    }
    makeStructureOrder($position->id, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '0.90000000', 'is_algo' => true,
    ]);

    $this->artisan('kraite:cron-check-drifts')->run();
    $this->artisan('kraite:cron-check-drifts')->run();
    $this->artisan('kraite:cron-check-drifts')->run();

    // Three consecutive ticks, same broken position — throttle keeps it at one.
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
});

it('emits one notification per broken position when several break at once', function (): void {
    $a = makeStructureFixture(totalLimitOrders: 4, token: 'AAA');
    $b = makeStructureFixture(totalLimitOrders: 4, token: 'BBB');

    foreach ([$a, $b] as $f) {
        $pid = $f['position']->id;
        makeStructureOrder($pid, ['type' => 'MARKET', 'status' => 'FILLED']);
        for ($i = 0; $i < 4; $i++) {
            makeStructureOrder($pid, ['type' => 'LIMIT', 'status' => 'NEW']);
        }
        makeStructureOrder($pid, [
            'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'NEW',
            'price' => '0.90000000', 'is_algo' => true,
        ]);
        // Both positions missing TP.
    }

    $this->artisan('kraite:cron-check-drifts')->run();

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 2);
});

/**
 * #503 regression (2026-07-12): a position whose take-profit FILLED is
 * closing — the close workflow cancels its SL + limits as a normal part
 * of the exit — so the transient "missing STOP_LOSS" while it is still
 * `active` for a heartbeat MUST NOT halt the whole bot. A fired exit is
 * never a broken structure. This false halt cooled trading for 6 hours.
 */
it('does NOT halt opens when a TP-filled (closing) position is missing its SL', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4, token: 'TPFILL');
    $pid = $f['position']->id;

    makeStructureOrder($pid, ['type' => 'MARKET', 'status' => 'FILLED']);
    // TP fired — the exit that closes the position.
    makeStructureOrder($pid, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'FILLED',
        'price' => '1.10000000', 'is_algo' => true,
    ]);
    // Close workflow cancelled the SL and every limit — the normal exit.
    makeStructureOrder($pid, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'CANCELLED',
        'price' => '0.90000000', 'is_algo' => true,
    ]);
    for ($i = 0; $i < 4; $i++) {
        makeStructureOrder($pid, ['type' => 'LIMIT', 'status' => 'CANCELLED']);
    }

    $this->artisan('kraite:cron-check-drifts')->run();

    // No false structure alarm, no global halt — it is closing, not broken.
    Notification::assertNothingSent();
    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();
});

it('does NOT halt opens when a STOP-triggered (closing) position is missing its TP', function (): void {
    $f = makeStructureFixture(totalLimitOrders: 4, token: 'SLTRIG');
    $pid = $f['position']->id;

    makeStructureOrder($pid, ['type' => 'MARKET', 'status' => 'FILLED']);
    // SL triggered — the exit fired on the downside.
    makeStructureOrder($pid, [
        'type' => 'STOP-MARKET', 'side' => 'SELL', 'status' => 'TRIGGERED',
        'price' => '0.90000000', 'is_algo' => true,
    ]);
    // The TP got cancelled by the close workflow.
    makeStructureOrder($pid, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'CANCELLED',
        'price' => '1.10000000', 'is_algo' => true,
    ]);
    for ($i = 0; $i < 4; $i++) {
        makeStructureOrder($pid, ['type' => 'LIMIT', 'status' => 'CANCELLED']);
    }

    $this->artisan('kraite:cron-check-drifts')->run();

    Notification::assertNothingSent();
    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();
});

it('STILL halts opens when a genuinely-open (no exit fired) position is missing its SL', function (): void {
    // Guard against over-correction: a real naked position — no TP/SL
    // fired, SL absent — must STILL trip the halt. The fix only excludes
    // positions that are exiting, never genuinely-open ones.
    $f = makeStructureFixture(totalLimitOrders: 4, token: 'NAKED');
    $pid = $f['position']->id;

    makeStructureOrder($pid, ['type' => 'MARKET', 'status' => 'FILLED']);
    for ($i = 0; $i < 4; $i++) {
        makeStructureOrder($pid, ['type' => 'LIMIT', 'status' => 'NEW']);
    }
    makeStructureOrder($pid, [
        'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'status' => 'NEW',
        'price' => '1.10000000', 'is_algo' => true,
    ]);
    // SL missing entirely, nothing fired — genuinely naked.

    $this->artisan('kraite:cron-check-drifts')->run();

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Scope 4 — trading-engine-health cooldown + incident writer + latch
// ---------------------------------------------------------------------------

function cleanMonitoringDir(): void
{
    $dir = base_path('monitoring');
    if (is_dir($dir)) {
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

it('cools the bot and writes ONE incident when fresh failed positions burst', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.failed_positions_threshold' => 2]);

    $f = makeStructureFixture(token: 'FAILB');
    seedHealthyStructure($f['position']->id, 4); // keep Scope 3 clean so Scope 4 is what fires
    // Two fresh failed positions in the window.
    Position::factory()->count(2)->create([
        'account_id' => $f['account']->id,
        'exchange_symbol_id' => $f['position']->exchange_symbol_id,
        'status' => 'failed',
        'updated_at' => now(),
    ]);

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
    expect(File::exists(base_path('monitoring/OPEN-INCIDENT')))->toBeTrue();

    $incidents = glob(base_path('monitoring/*.md')) ?: [];
    expect($incidents)->toHaveCount(1);
    expect(File::get($incidents[0]))->toContain('failed_positions_burst')->toContain('narrated: NO');

    // Breaker transitions are app-only; no direct Pushover transport remains.
    Illuminate\Support\Facades\Http::assertNothingSent();

    cleanMonitoringDir();
});

it('cools when the failed trading-step storm crosses threshold', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.failed_steps_threshold' => 3, 'kraite.guard.failed_positions_threshold' => 99]);

    Steps::usingPrefix('trading', fn () => Step::query()->insert(
        collect(range(1, 3))->map(fn () => [
            'class' => 'X', 'type' => 'default', 'queue' => 'trading',
            'state' => Failed::class,
            'block_uuid' => (string) Str::uuid(),
            'index' => 1, 'created_at' => now(), 'updated_at' => now(),
        ])->all(),
    ));

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
    expect(glob(base_path('monitoring/*.md')))->toHaveCount(1);

    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckDriftsCommand::class))->getFileName(),
    );
    expect($source)
        ->not->toContain("DB::table('trading_steps')")
        ->toContain('Failed::class');

    cleanMonitoringDir();
});

it('cools when recent exchange API failures cross the engine-health threshold', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config([
        'kraite.guard.exchange_error_log_threshold' => 3,
        'kraite.guard.failed_positions_threshold' => 99,
        'kraite.guard.failed_steps_threshold' => 99,
    ]);

    $exchange = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance exchange storm',
    ]);

    ApiRequestLog::withoutEvents(fn () => ApiRequestLog::factory()
        ->count(3)
        ->serverError()
        ->create([
            'api_system_id' => $exchange->id,
            'created_at' => now(),
        ]));

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
    $incident = collect(glob(base_path('monitoring/*.md')) ?: [])->sole();
    expect(File::get($incident))->toContain('exchange_error_storm');
    cleanMonitoringDir();
});

it('ignores successful, stale, and non-exchange API requests in the exchange-error storm signal', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config([
        'kraite.guard.window_minutes' => 20,
        'kraite.guard.exchange_error_log_threshold' => 2,
        'kraite.guard.failed_positions_threshold' => 99,
        'kraite.guard.failed_steps_threshold' => 99,
    ]);

    $exchange = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance exchange signal noise',
    ]);
    $provider = ApiSystem::factory()->create([
        'canonical' => 'taapi',
        'name' => 'Taapi signal noise',
        'is_exchange' => false,
    ]);

    ApiRequestLog::withoutEvents(function () use ($exchange, $provider): void {
        ApiRequestLog::factory()->successful()->create([
            'api_system_id' => $exchange->id,
            'created_at' => now(),
        ]);
        ApiRequestLog::factory()->serverError()->create([
            'api_system_id' => $exchange->id,
            'created_at' => now()->subMinutes(21),
        ]);
        ApiRequestLog::factory()->serverError()->create([
            'api_system_id' => $provider->id,
            'created_at' => now(),
        ]);
    });

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue()
        ->and(File::exists(base_path('monitoring/OPEN-INCIDENT')))->toBeFalse();
    cleanMonitoringDir();
});

it('latches: once cooled, a second pass writes no new incident', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.failed_positions_threshold' => 1]);

    $f = makeStructureFixture(token: 'LATCH');
    seedHealthyStructure($f['position']->id, 4); // keep Scope 3 clean so Scope 4 is what fires
    Position::factory()->create([
        'account_id' => $f['account']->id,
        'exchange_symbol_id' => $f['position']->exchange_symbol_id,
        'status' => 'failed', 'updated_at' => now(),
    ]);

    $this->artisan('kraite:cron-check-drifts')->run();
    $first = glob(base_path('monitoring/*.md')) ?: [];
    expect($first)->toHaveCount(1);

    // Second pass while already cooled — no new incident, no re-cool.
    $this->artisan('kraite:cron-check-drifts')->run();
    expect(glob(base_path('monitoring/*.md')))->toHaveCount(1);

    cleanMonitoringDir();
});

it('stays green (no cool, no incident) when engine health is clean', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();
    expect(File::exists(base_path('monitoring/OPEN-INCIDENT')))->toBeFalse();
    cleanMonitoringDir();
});

// ---------------------------------------------------------------------------
// Scope 5 — error-storm cooldown auto-release (2026-07-28)
//
// The latch was manual-only: both 2026-07-27/28 storm trips parked new
// openings for hours after the errors stopped, waiting for a human. The
// guard now releases its OWN storm latches once the exchange ledger has
// been clean for the recovery window. Latches from any other trigger —
// or set by hand with no incident on file — stay strictly manual.
// ---------------------------------------------------------------------------

function enterStormLatch(): void
{
    (new TradingCooldown)->enter('exchange_error_storm', [
        'exchange_errors' => 16,
        'threshold' => 15,
        'window_minutes' => 20,
    ]);
    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
}

function seedExchangeErrorRow(int $minutesAgo, string $response = '{"code":-1000,"msg":"Internal error"}', int $code = 500): void
{
    $apiSystem = ApiSystem::query()->where('is_exchange', true)->first()
        ?? ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);

    DB::table('api_request_logs')->insert([
        'api_system_id' => $apiSystem->id,
        'http_response_code' => $code,
        'response' => $response,
        'path' => '/test/storm',
        'http_method' => 'POST',
        'hostname' => 'kraite',
        'created_at' => now()->subMinutes($minutesAgo),
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);
}

it('auto-resumes openings when a storm latch goes clean for the recovery window', function (): void {
    Illuminate\Support\Facades\Http::fake();
    Notification::fake();
    cleanMonitoringDir();
    config(['kraite.guard.exchange_error_recovery_minutes' => 30]);
    Kraite::find(1)->updateSaving(['notifications_enabled' => true]);
    NotificationDefinition::query()
        ->whereIn('canonical', ['trading_guard_paused', 'trading_guard_recovered'])
        ->update(['is_active' => true]);
    $trader = Account::factory()->create()->user;

    enterStormLatch();
    // Ledger clean: the only rows are older than the recovery window.
    seedExchangeErrorRow(minutesAgo: 45);

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue()
        ->and(File::exists(base_path('monitoring/OPEN-INCIDENT')))->toBeFalse();

    $incidents = glob(base_path('monitoring/*.md')) ?: [];
    expect($incidents)->toHaveCount(1)
        ->and(File::get($incidents[0]))->toContain('auto-released');

    Notification::assertSentTo(
        $trader,
        AlertNotification::class,
        fn (AlertNotification $notification, array $channels): bool => $notification->canonical === 'trading_guard_paused'
            && $channels === [AppPushChannel::class],
    );
    Notification::assertSentTo(
        $trader,
        AlertNotification::class,
        fn (AlertNotification $notification, array $channels): bool => $notification->canonical === 'trading_guard_recovered'
            && $channels === [AppPushChannel::class],
    );
    Illuminate\Support\Facades\Http::assertNothingSent();

    cleanMonitoringDir();
});

it('keeps the latch held while exchange errors sit inside the recovery window', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.exchange_error_recovery_minutes' => 30]);

    enterStormLatch();
    seedExchangeErrorRow(minutesAgo: 5);

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse()
        ->and(File::exists(base_path('monitoring/OPEN-INCIDENT')))->toBeTrue();

    cleanMonitoringDir();
});

it('releases even when benign already-flat rejections sit inside the window', function (): void {
    // Interlock with the storm-counter fix: self-inflicted -2022 noise
    // neither trips the latch nor holds its recovery.
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.exchange_error_recovery_minutes' => 30]);

    enterStormLatch();
    seedExchangeErrorRow(minutesAgo: 3, response: '{"code":-2022,"msg":"ReduceOnly Order is rejected."}', code: 400);

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeTrue();

    cleanMonitoringDir();
});

it('never auto-clears a latch created by a different trigger', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.exchange_error_recovery_minutes' => 30]);

    (new TradingCooldown)->enter('failed_positions_burst', [
        'failed_positions' => 2,
    ]);

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse()
        ->and(File::exists(base_path('monitoring/OPEN-INCIDENT')))->toBeTrue();

    cleanMonitoringDir();
});

it('never auto-clears a manually set latch with no incident on file', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.exchange_error_recovery_minutes' => 30]);

    Kraite::query()->first()->update(['allow_opening_positions' => false]);

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();

    cleanMonitoringDir();
});
