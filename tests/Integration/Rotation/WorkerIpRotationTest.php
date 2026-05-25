<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Server;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Tests\Support\StepTester;
use Tests\Support\TestBinanceApiableJob;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('integration', 'rotation', 'worker-ip');

/*
|--------------------------------------------------------------------------
| Worker-IP Rotation Tests
|--------------------------------------------------------------------------
|
| Verifies that BaseApiableJob::compute() detects a ban for the CURRENT
| worker's IP and re-routes the step to a clean worker's per-hostname
| queue rather than retrying locally. Behaviour:
|
| - If at least one worker IP is unbanned for the (account, api_system)
|   pair, the step is rotated (queue changed, retries unchanged, state
|   reset to pending, started_at cleared).
| - If every apiable worker is banned, the step fails terminally and
|   the account is disabled with a loud notification.
|
| The rotation primitive is `Step->rotateToQueue($queueName)` shipped
| by step-dispatcher v1.12.3.
|
*/

beforeEach(function (): void {
    // The StepObserver's saving() hook drops any queue name that isn't in
    // step-dispatcher.queues.valid back to 'default'. Rotation targets the
    // per-hostname worker queues, so they must be on the allowlist for the
    // rotation to actually persist.
    config()->set('step-dispatcher.queues.valid', ['eos', 'iris', 'nyx', 'tyche', 'athena', 'cronjobs']);

    // The test suite ships NOTIFICATIONS_ENABLED=false in phpunit.xml so most
    // tests don't hit the notification pipeline accidentally; flip it on for
    // this rotation suite because the deactivation cascade's loud
    // "portfolio at risk" alert is part of the behavioural contract under
    // test.
    config()->set('kraite.notifications_enabled', true);
});

/**
 * Seed the apiable worker fleet with three distinct public IPs and
 * per-hostname queue names. Mirrors the production layout (eos / iris /
 * nyx) used by Kraite's Binance trading pool.
 *
 * @return array{eos: Server, iris: Server, nyx: Server}
 */
function seedRotationFleet(): array
{
    return [
        'eos' => Server::create([
            'hostname' => 'eos',
            'ip_address' => '203.0.113.10',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'eos',
            'type' => 'worker',
            'description' => 'rotation test — eos',
        ]),
        'iris' => Server::create([
            'hostname' => 'iris',
            'ip_address' => '203.0.113.11',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'iris',
            'type' => 'worker',
            'description' => 'rotation test — iris',
        ]),
        'nyx' => Server::create([
            'hostname' => 'nyx',
            'ip_address' => '203.0.113.12',
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => 'nyx',
            'type' => 'worker',
            'description' => 'rotation test — nyx',
        ]),
    ];
}

/**
 * Force Kraite::ip() to return a specific IP for the duration of one test,
 * simulating "we are currently running on worker <X>". Bypasses the
 * gethostname() → servers-table lookup by warming the IP cache directly.
 */
function pretendCurrentWorkerIs(Server $server): void
{
    Cache::put(Kraite::IP_CACHE_KEY, $server->ip_address, Kraite::IP_CACHE_TTL_SECONDS);
}

it('rotates a step to a clean worker queue when the current worker IP is banned', function (): void {
    $fleet = seedRotationFleet();
    pretendCurrentWorkerIs($fleet['eos']);

    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Pre-existing ban on the CURRENT worker's IP only — iris and nyx are
    // both clean and either is a valid rotation target.
    ForbiddenHostname::create([
        'api_system_id' => $apiSystem->id,
        'account_id' => $account->id,
        'ip_address' => $fleet['eos']->ip_address,
        'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
        'forbidden_until' => now()->addHour(),
    ]);

    $step = StepTester::createSteps([
        ['queue' => 'cronjobs', 'arguments' => ['accountId' => $account->id]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('rotation_redispatches_to_clean_worker')
        ->test();

    $step->refresh();

    // Step is queued on a clean worker — iris or nyx, never eos (banned).
    expect($step->queue)->toBeIn(['iris', 'nyx'])
        ->and($step->state->value())->toBe('pending')
        ->and($step->retries)->toBe(0)
        ->and($step->started_at)->toBeNull();

    // computeApiable() never ran — rotation short-circuits before the API.
    $events = array_column($step->response['execution_path'] ?? [], 'event');
    expect($events)->not->toContain('computeApiable:start');
});

it('fires a critical portfolio-at-risk notification to the account owner when deactivating', function (): void {
    \Kraite\Core\Models\Notification::create([
        'canonical' => 'account_all_workers_blacklisted',
        'title' => 'URGENT — Account Deactivated, Portfolio Unmanaged',
        'description' => 'Test row',
        'default_severity' => 'critical',
        'is_active' => true,
        'verified' => true,
        'cache_duration' => 3600,
        'cache_key' => ['account_id', 'api_system'],
    ]);
    \Kraite\Core\Support\NotificationService::flushNotificationCache();

    Notification::fake();

    $fleet = seedRotationFleet();
    pretendCurrentWorkerIs($fleet['eos']);

    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
        'name' => 'Main Binance',
    ]);

    foreach ($fleet as $server) {
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => $server->ip_address,
            'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            'forbidden_until' => now()->addHour(),
        ]);
    }

    $step = StepTester::createSteps([
        ['queue' => 'cronjobs', 'arguments' => ['accountId' => $account->id]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'],
        ])
        ->withLabel('deactivation_fires_loud_notification')
        ->test();

    // Exactly one critical notification to the account owner — distinct
    // from the standard per-ban ForbiddenHostnameObserver notification.
    // This is the "portfolio at risk" loud channel: every worker IP is
    // blacklisted on the exchange, so the system cannot communicate with
    // the user's account at all until they intervene.
    Notification::assertSentTo(
        $user,
        AlertNotification::class,
        function (AlertNotification $n): bool {
            return $n->canonical === 'account_all_workers_blacklisted';
        }
    );
});

it('fails the step terminally and deactivates the account when every worker IP is exhausted', function (): void {
    $fleet = seedRotationFleet();
    pretendCurrentWorkerIs($fleet['eos']);

    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Every apiable worker is banned for this (account, api_system) pair.
    // Rotation has nowhere to go → terminal failure + account deactivation.
    foreach ($fleet as $server) {
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => $server->ip_address,
            'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            'forbidden_until' => now()->addHour(),
        ]);
    }

    $step = StepTester::createSteps([
        ['queue' => 'cronjobs', 'arguments' => ['accountId' => $account->id]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'],
        ])
        ->withLabel('exhausted_fleet_deactivates_account')
        ->test();

    $step->refresh();
    $account->refresh();

    expect($step->state->value())->toBe('failed');

    expect($account->is_active)->toBeFalse()
        ->and($account->disabled_at)->not->toBeNull()
        ->and($account->disabled_reason)
        ->toBe('All worker IPs blacklisted on Binance — fix whitelist/API key and reactivate');
});

it('fails instantly and deactivates the account on account_blocked without attempting rotation', function (): void {
    $fleet = seedRotationFleet();
    pretendCurrentWorkerIs($fleet['eos']);

    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // account_blocked = bad/revoked API key. Switching IP cannot help —
    // no IP rotation is attempted; the account is deactivated on the first
    // detection so the user investigates immediately.
    ForbiddenHostname::create([
        'api_system_id' => $apiSystem->id,
        'account_id' => $account->id,
        'ip_address' => $fleet['eos']->ip_address,
        'type' => ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
        'forbidden_until' => now()->addHour(),
    ]);

    $step = StepTester::createSteps([
        ['queue' => 'cronjobs', 'arguments' => ['accountId' => $account->id]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'],
        ])
        ->withLabel('account_blocked_fails_without_rotation')
        ->test();

    $step->refresh();
    $account->refresh();

    // Terminal failure on first attempt, queue NOT rotated (account_blocked
    // skips the rotation branch entirely).
    expect($step->state->value())->toBe('failed')
        ->and($step->queue)->toBe('cronjobs')
        ->and($step->retries)->toBe(0);

    expect($account->is_active)->toBeFalse()
        ->and($account->disabled_reason)
        ->toBe('All worker IPs blacklisted on Binance — fix whitelist/API key and reactivate');

    // computeApiable() was never called — the ban gate fired before the API.
    $events = array_column($step->response['execution_path'] ?? [], 'event');
    expect($events)->not->toContain('computeApiable:start');
});

it('does not rotate when the current worker IP is clean', function (): void {
    $fleet = seedRotationFleet();
    pretendCurrentWorkerIs($fleet['eos']);

    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Ban only iris — eos (current) and nyx are clean. No rotation needed.
    ForbiddenHostname::create([
        'api_system_id' => $apiSystem->id,
        'account_id' => $account->id,
        'ip_address' => $fleet['iris']->ip_address,
        'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
        'forbidden_until' => now()->addHour(),
    ]);

    $step = StepTester::createSteps([
        ['queue' => 'cronjobs', 'arguments' => ['accountId' => $account->id]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->withLabel('no_rotation_when_current_ip_is_clean')
        ->test();

    $step->refresh();

    // Step was processed on the original queue — no rotation, normal completion.
    expect($step->queue)->toBe('cronjobs')
        ->and($step->state->value())->toBe('completed');

    $events = array_column($step->response['execution_path'] ?? [], 'event');
    expect($events)->toContain('computeApiable:start');
});
