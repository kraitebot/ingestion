<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Server;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\StepRouter;
use StepDispatcher\Exceptions\NoCleanWorkerException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use Tests\Support\StepTester;
use Tests\Support\TestBinanceApiableJob;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('integration', 'routing', 'step-router');

/*
|--------------------------------------------------------------------------
| StepRouter — dispatch-time queue resolver tests
|--------------------------------------------------------------------------
|
| StepRouter is registered as the step-dispatcher v1.13.0 queue resolver
| in `CoreServiceProvider::boot()`. At dispatch time the router decides
| the physical per-hostname queue (e.g. `positions-eos`) for a step based
| on:
|
|   - the step's logical queue (stripped of any prior hostname suffix
|     when re-resolving on a retry)
|   - the candidate workers for that logical queue (read from
|     `config('kraite.horizon.workers')`)
|   - the active forbidden_hostnames rows for the step's
|     (account, api_system) pair — both account-scoped and system-wide
|
| Terminal cascades (account.is_active=0 + `account_all_workers_blacklisted`
| notification + NoCleanWorkerException) fire when:
|   (a) an `account_blocked` ban exists (bad API key, no IP rotation
|       can save it), OR
|   (b) every eligible worker IP is banned AND at least one ban is the
|       permanent (`ip_not_whitelisted`) type.
|
| When only temporary bans (`ip_rate_limited` / `ip_banned` with future
| expiry) exhaust the candidate set, the router returns `null` (no
| opinion) — step-dispatcher leaves `step.queue` as-is and the existing
| retry mechanics take over until the ban window expires.
|
*/

beforeEach(function (): void {
    // step-dispatcher's StepObserver demotes any queue name not on the
    // allowlist back to 'default'. The router pushes onto per-hostname
    // queues (`positions-eos` etc), so they must be declared valid.
    config()->set('step-dispatcher.queues.valid', [
        'default',
        'positions',
        'positions-eos', 'positions-iris', 'positions-nyx',
        'orders', 'orders-eos', 'orders-iris', 'orders-nyx',
        'cronjobs', 'cronjobs-tyche',
        'eos', 'iris', 'nyx', 'tyche', 'athena',
    ]);

    // Single-source-of-truth fleet topology (consumed by both
    // config/horizon.php and StepRouter::candidatesFor).
    config()->set('kraite.horizon.workers', [
        'eos' => ['positions' => ['processes' => 5], 'orders' => ['processes' => 8], 'eos' => ['processes' => 1]],
        'iris' => ['positions' => ['processes' => 5], 'orders' => ['processes' => 8], 'iris' => ['processes' => 1]],
        'nyx' => ['positions' => ['processes' => 5], 'orders' => ['processes' => 8], 'nyx' => ['processes' => 1]],
        'tyche' => ['cronjobs' => ['processes' => 3], 'indicators' => ['processes' => 10], 'tyche' => ['processes' => 1]],
    ]);

    // Deactivation-cascade notifications must reach the fake — flip the
    // global notifications kill-switch on for this suite only.
    config()->set('kraite.notifications_enabled', true);
});

/**
 * Seed the trading fleet (eos / iris / nyx) plus tyche so the router has
 * Servers to pluck for ban filtering. IPs are documentation-RFC ranges
 * to ensure they never collide with anything real.
 *
 * @return array{eos: Server, iris: Server, nyx: Server, tyche: Server}
 */
function seedFleet(): array
{
    return [
        'eos' => Server::create([
            'hostname' => 'eos',
            'ip_address' => '203.0.113.10',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'eos',
            'type' => 'worker',
        ]),
        'iris' => Server::create([
            'hostname' => 'iris',
            'ip_address' => '203.0.113.11',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'iris',
            'type' => 'worker',
        ]),
        'nyx' => Server::create([
            'hostname' => 'nyx',
            'ip_address' => '203.0.113.12',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'nyx',
            'type' => 'worker',
        ]),
        'tyche' => Server::create([
            'hostname' => 'tyche',
            'ip_address' => '203.0.113.13',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'tyche',
            'type' => 'worker',
        ]),
    ];
}

function makeAccountStep(string $logicalQueue, int $accountId): Step
{
    return StepTester::createSteps([[
        'queue' => $logicalQueue,
        'arguments' => ['accountId' => $accountId],
        'relatable_type' => Account::class,
        'relatable_id' => $accountId,
    ]], TestBinanceApiableJob::class)[0];
}

describe('Routing — unbanned scenarios', function (): void {
    it('routes a positions step to a per-hostname queue when no bans exist', function (): void {
        seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
            'relatable_type' => Account::class,
            'relatable_id' => $account->id,
        ]);

        $resolved = $router->resolveQueueName($step);

        expect($resolved)->toBeIn(['positions-eos', 'positions-iris', 'positions-nyx']);
    });

    it('keeps a step on its existing per-hostname queue when no ban is active for that worker', function (): void {
        seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        // Step already carries a physical queue from a prior dispatch
        // (positions-eos). The router strips the hostname suffix to
        // recover the logical (positions) then re-picks. With no bans
        // any of the 3 candidates is valid; the resolved queue can be
        // any of them including the original.
        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions-eos',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        expect($resolved)->toBeIn(['positions-eos', 'positions-iris', 'positions-nyx']);
    });

    it('routes orchestrator (no-account) steps to any eligible worker without ban filtering', function (): void {
        seedFleet();

        // Pre-seed a ban for ALL accounts on eos — would block this
        // worker if the router were account-aware. But this step has
        // no accountId in arguments, so the ban filter is skipped.
        ForbiddenHostname::create([
            'api_system_id' => ApiSystem::factory()->create(['canonical' => 'binance'])->id,
            'account_id' => null,
            'ip_address' => '203.0.113.10', // eos
            'type' => ForbiddenHostname::TYPE_IP_BANNED,
            'forbidden_until' => now()->addHour(),
        ]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => [], // no accountId
        ]);

        $resolved = $router->resolveQueueName($step);

        // Without an account context the ban filter doesn't fire — any
        // candidate, including eos, is valid.
        expect($resolved)->toBeIn(['positions-eos', 'positions-iris', 'positions-nyx']);
    });

    it('returns null for an unknown logical queue with no subscribers', function (): void {
        seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'some-unknown-category',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        // Router has no opinion for unknown logical queues — defers to
        // step-dispatcher's default behaviour (uses step.queue verbatim).
        expect($resolved)->toBeNull();
    });
});

describe('Routing — ban filtering', function (): void {
    it('filters out a candidate worker whose IP is in an active ip_not_whitelisted ban', function (): void {
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => $fleet['eos']->ip_address,
            'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            'forbidden_until' => now()->addHour(),
        ]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        // eos is banned, iris and nyx are clean.
        expect($resolved)->toBeIn(['positions-iris', 'positions-nyx'])
            ->and($resolved)->not->toBe('positions-eos');
    });

    it('honours system-wide bans (account_id=NULL) for every account on the same api_system', function (): void {
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        // System-wide ban (account_id IS NULL) on iris — should affect
        // every account routing to this api_system.
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => null,
            'ip_address' => $fleet['iris']->ip_address,
            'type' => ForbiddenHostname::TYPE_IP_BANNED,
            'forbidden_until' => now()->addHour(),
        ]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        expect($resolved)->toBeIn(['positions-eos', 'positions-nyx'])
            ->and($resolved)->not->toBe('positions-iris');
    });

    it('does not apply a ban from a different api_system (cross-exchange isolation)', function (): void {
        $fleet = seedFleet();

        $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
        $bybit = ApiSystem::factory()->create(['canonical' => 'bybit']);

        $user = User::factory()->create();
        $binanceAccount = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $binance->id]);

        // Ban on eos's IP for BYBIT only — Binance routing should ignore it.
        ForbiddenHostname::create([
            'api_system_id' => $bybit->id,
            'account_id' => null,
            'ip_address' => $fleet['eos']->ip_address,
            'type' => ForbiddenHostname::TYPE_IP_BANNED,
            'forbidden_until' => null,
        ]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $binanceAccount->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        // Bybit ban doesn't affect Binance — all 3 workers eligible.
        expect($resolved)->toBeIn(['positions-eos', 'positions-iris', 'positions-nyx']);
    });

    it('ignores expired bans (forbidden_until in the past)', function (): void {
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => $fleet['eos']->ip_address,
            'type' => ForbiddenHostname::TYPE_IP_RATE_LIMITED,
            'forbidden_until' => now()->subMinutes(5),
        ]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        // Ban expired → all 3 workers eligible again.
        expect($resolved)->toBeIn(['positions-eos', 'positions-iris', 'positions-nyx']);
    });
});

describe('Terminal cascade', function (): void {
    it('throws NoCleanWorkerException + deactivates the account when account_blocked exists', function (): void {
        Notification::fake();
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance', 'name' => 'Binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        // account_blocked is the "bad API key" type. Even one such ban
        // (regardless of which worker IP it was discovered on) triggers
        // the cascade — no IP rotation can save it.
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => $fleet['eos']->ip_address,
            'type' => ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
            'forbidden_until' => now()->addHour(),
        ]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        expect(fn () => $router->resolveQueueName($step))->toThrow(NoCleanWorkerException::class);

        $account->refresh();
        expect($account->is_active)->toBeFalse()
            ->and($account->disabled_reason)->toContain('Binance');
    });

    it('throws NoCleanWorkerException + deactivates when every eligible worker has a permanent ban', function (): void {
        Notification::fake();
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance', 'name' => 'Binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        foreach (['eos', 'iris', 'nyx'] as $hostname) {
            ForbiddenHostname::create([
                'api_system_id' => $apiSystem->id,
                'account_id' => $account->id,
                'ip_address' => $fleet[$hostname]->ip_address,
                'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                'forbidden_until' => now()->addHour(),
            ]);
        }

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        expect(fn () => $router->resolveQueueName($step))->toThrow(NoCleanWorkerException::class);

        $account->refresh();
        expect($account->is_active)->toBeFalse();
    });

    it('fires the account_all_workers_blacklisted notification on terminal cascade', function (): void {
        Kraite\Core\Models\Notification::create([
            'canonical' => 'account_all_workers_blacklisted',
            'title' => 'URGENT — Account Deactivated, Portfolio Unmanaged',
            'description' => 'Test seed',
            'default_severity' => 'critical',
            'is_active' => true,
            'verified' => true,
            'cache_duration' => 3600,
            'cache_key' => ['account_id', 'api_system'],
        ]);
        Kraite\Core\Support\NotificationService::flushNotificationCache();

        Notification::fake();
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance', 'name' => 'Binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        foreach (['eos', 'iris', 'nyx'] as $hostname) {
            ForbiddenHostname::create([
                'api_system_id' => $apiSystem->id,
                'account_id' => $account->id,
                'ip_address' => $fleet[$hostname]->ip_address,
                'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                'forbidden_until' => now()->addHour(),
            ]);
        }

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        try {
            $router->resolveQueueName($step);
        } catch (NoCleanWorkerException) {
            // expected
        }

        Notification::assertSentTo(
            $user,
            AlertNotification::class,
            function (AlertNotification $n): bool {
                return $n->canonical === 'account_all_workers_blacklisted';
            }
        );
    });
});

describe('Temporary-only ban fallback', function (): void {
    it('returns null (no deactivation) when ALL candidates have only TEMPORARY bans', function (): void {
        Notification::fake();
        $fleet = seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        // All 3 trading workers rate-limited with a future expiry — they
        // will recover when the window passes. Deactivating the account
        // would be too aggressive. Router returns null → step.queue is
        // left alone, existing retry mechanics wait it out.
        foreach (['eos', 'iris', 'nyx'] as $hostname) {
            ForbiddenHostname::create([
                'api_system_id' => $apiSystem->id,
                'account_id' => null,
                'ip_address' => $fleet[$hostname]->ip_address,
                'type' => ForbiddenHostname::TYPE_IP_RATE_LIMITED,
                'forbidden_until' => now()->addMinutes(5),
            ]);
        }

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        expect($resolved)->toBeNull();

        $account->refresh();
        expect($account->is_active)->toBeTrue();
    });
});

describe('Strip-suffix on retry', function (): void {
    it('strips a known-hostname suffix from step.queue to recover the logical category', function (): void {
        seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions-eos', // physical from a prior dispatch
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        // Router strips '-eos', recovers 'positions', re-picks among
        // the 3 trading workers. The result is one of the per-hostname
        // queues (the previous eos OR a fresh iris/nyx pick).
        expect($resolved)->toBeIn(['positions-eos', 'positions-iris', 'positions-nyx']);
    });

    it('does NOT strip a suffix that does not match any registered hostname', function (): void {
        seedFleet();

        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'api_system_id' => $apiSystem->id]);

        // No 'foobar' hostname in the servers table — the suffix
        // shouldn't be stripped, so the logical queue stays as the
        // full string 'positions-foobar' (which has no candidates →
        // router returns null).
        $router = new StepRouter;
        $step = Step::factory()->create([
            'queue' => 'positions-foobar',
            'state' => Pending::class,
            'class' => TestBinanceApiableJob::class,
            'arguments' => ['accountId' => $account->id],
        ]);

        $resolved = $router->resolveQueueName($step);

        expect($resolved)->toBeNull();
    });
});
