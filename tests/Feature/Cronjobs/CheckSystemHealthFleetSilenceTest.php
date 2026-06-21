<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Server;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the fleet-metrics silence alert lifecycle on `CheckSystemHealthCommand`.
 *
 * Pre-fix, two storms were guaranteed: (1) the canonical's 300s throttle is
 * shorter than the check cadence, so a down box paged on EVERY run; the
 * check now passes its own `fleet_metrics.alert_throttle_seconds` window.
 * (2) a box seeded into the roster before its Horizon warmed (the normal
 * provisioning sequence — palaemon/aristaeus pattern) paged as `missing`
 * from the moment its row existed; `missing` rows now sit out a
 * `provisioning_grace_seconds` window anchored on `servers.created_at`.
 * `stale` is never graced — that box reported before, silence is real.
 *
 * Also pins the stale-detail string: a snapshot whose `reported_at` is
 * absent/unparseable classifies `stale` with `age_seconds = null`, which
 * used to interpolate as "is s stale"; it now names the unreadable stamp.
 */
uses(RefreshDatabase::class);

const FLEET_TEST_HOSTS = ['fleettest-graced', 'fleettest-missing', 'fleettest-stale', 'fleettest-corrupt', 'fleettest-throttle'];

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
    Illuminate\Support\Once::flush();

    // NEVER Cache::flush() here: tests/Pest.php pre-seeds Kraite::IP_CACHE_KEY
    // every test; flushing it sends NotificationMessageBuilder down the
    // Kraite::ip() ipify fallback, which preventStrayRequests() then kills —
    // every alert send silently returns false and the assertions go blind.

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );

    foreach (FLEET_TEST_HOSTS as $hostname) {
        try {
            Redis::connection('fleet')->del("kraite:fleet:{$hostname}");
        } catch (Throwable) {
            // Local dev / CI without Redis: tests will skip below.
        }
    }
});

afterEach(function (): void {
    foreach (FLEET_TEST_HOSTS as $hostname) {
        try {
            Redis::connection('fleet')->del("kraite:fleet:{$hostname}");
        } catch (Throwable) {
        }
    }
});

function registerFleetBox(string $hostname, int $registeredSecondsAgo): Server
{
    $server = Server::create([
        'hostname' => $hostname,
        'ip_address' => '10.99.0.1',
        'is_apiable' => false,
        'needs_whitelisting' => false,
        'own_queue_name' => $hostname,
        'description' => 'fleet-silence test box',
        'type' => 'worker',
    ]);

    DB::table('servers')->where('id', $server->id)->update([
        'created_at' => now()->subSeconds($registeredSecondsAgo),
    ]);

    return $server->refresh();
}

function writeFleetSnapshot(string $hostname, array $payload): void
{
    try {
        Redis::connection('fleet')->setex(
            "kraite:fleet:{$hostname}",
            300,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    } catch (Throwable $e) {
        test()->markTestSkipped("Redis unavailable in test env: {$e->getMessage()}");
    }
}

it('does not page for a missing box still inside the provisioning grace window', function (): void {
    registerFleetBox('fleettest-graced', registeredSecondsAgo: 600); // well inside the 86400s default

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'fleettest-graced')
    );
});

it('pages for a missing box once the provisioning grace window has closed', function (): void {
    registerFleetBox('fleettest-missing', registeredSecondsAgo: 2 * 86400);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'fleettest-missing')
            && str_contains((string) ($n->title ?? ''), 'missing')
    );
});

it('pages for a stale box even inside the grace window because it has reported before', function (): void {
    registerFleetBox('fleettest-stale', registeredSecondsAgo: 600);
    writeFleetSnapshot('fleettest-stale', [
        'hostname' => 'fleettest-stale',
        'reported_at' => now()->subSeconds(3600)->toIso8601String(), // far past stale_after (720s)
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'fleettest-stale')
            && str_contains((string) ($n->title ?? ''), 'stale')
    );
});

it('names an unreadable reported_at stamp instead of interpolating a null age', function (): void {
    registerFleetBox('fleettest-corrupt', registeredSecondsAgo: 2 * 86400);
    writeFleetSnapshot('fleettest-corrupt', [
        'hostname' => 'fleettest-corrupt',
        // No reported_at at all — classify() yields stale with age_seconds null.
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($n) {
            $message = (string) ($n->message ?? '');

            return str_contains((string) ($n->title ?? ''), 'fleettest-corrupt')
                && str_contains($message, 'unreadable reported_at stamp')
                && ! str_contains($message, 'is s stale');
        }
    );
});

it('throttles the re-page across consecutive runs instead of paging on every tick', function (): void {
    registerFleetBox('fleettest-throttle', registeredSecondsAgo: 2 * 86400);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();
    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    // Other health checks may legitimately fire in the same runs, so count
    // only the notifications naming our test box: two runs, one page.
    $count = 0;

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($n) use (&$count) {
            if (str_contains((string) ($n->title ?? ''), 'fleettest-throttle')) {
                $count++;
            }

            return true;
        }
    );

    expect($count)->toBe(1);
});
