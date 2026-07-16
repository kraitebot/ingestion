<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
    Notification::assertCount(1);
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
    Notification::assertCount(1);
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
    Notification::assertCount(1);
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
    Notification::assertCount(1);
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

    Notification::assertCount(2);
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

    Notification::assertSentTimes(AlertNotification::class, 1);
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

    // Guard pushover fired once.
    Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'pushover.net'));

    cleanMonitoringDir();
});

it('cools when the failed trading-step storm crosses threshold', function (): void {
    Illuminate\Support\Facades\Http::fake();
    cleanMonitoringDir();
    config(['kraite.guard.failed_steps_threshold' => 3, 'kraite.guard.failed_positions_threshold' => 99]);

    Illuminate\Support\Facades\DB::table('trading_steps')->insert(collect(range(1, 3))->map(fn () => [
        'class' => 'X', 'type' => 'default', 'queue' => 'trading',
        'state' => 'StepDispatcher\\States\\Failed',
        'block_uuid' => (string) Str::uuid(),
        'index' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])->all());

    $this->artisan('kraite:cron-check-drifts')->run();

    expect(Kraite::query()->first()->allow_opening_positions)->toBeFalse();
    expect(glob(base_path('monitoring/*.md')))->toHaveCount(1);
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
