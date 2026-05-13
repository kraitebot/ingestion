<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the per-queue Horizon depth alert on `CheckSystemHealthCommand`.
 *
 * Pre-fix, `checkHorizonQueueDepth()` summed all 7 canonical queues
 * into a single depth and alerted at 5000 — masking dangerous
 * safety-critical backlogs. A `positions` queue with 100 pending ops
 * means 100 delayed exposure-protection actions across accounts; sum
 * across queues was 100, well below 5000, so no Pushover.
 *
 * Post-fix, each queue carries its own threshold tuned to its
 * operational profile (positions/orders/user-data-stream tight,
 * indicators loose). Each breaching queue fires a distinct signal
 * `horizon_queue_depth_{queue}` so the operator's Pushover names which
 * queue and which workers to investigate.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
    Illuminate\Support\Once::flush();

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );

    // Flush all canonical queues we touch in these tests so prior
    // runs don't leak state.
    foreach (['default', 'priority', 'positions', 'orders', 'cronjobs', 'indicators', 'user-data-stream'] as $queue) {
        try {
            Redis::connection()->del("queues:{$queue}");
        } catch (Throwable) {
            // Local dev / CI without Redis: tests will skip below.
        }
    }
});

afterEach(function (): void {
    foreach (['default', 'priority', 'positions', 'orders', 'cronjobs', 'indicators', 'user-data-stream'] as $queue) {
        try {
            Redis::connection()->del("queues:{$queue}");
        } catch (Throwable) {
        }
    }
});

function pushFakeJobs(string $queue, int $count): void
{
    try {
        for ($i = 0; $i < $count; $i++) {
            Redis::connection()->rpush("queues:{$queue}", 'fake-payload-'.$i);
        }
    } catch (Throwable $e) {
        test()->markTestSkipped("Redis unavailable in test env: {$e->getMessage()}");
    }
}

it('does not fire any horizon_queue_depth alert when all queues are below their per-queue threshold', function (): void {
    pushFakeJobs('positions', 10);   // below 50
    pushFakeJobs('orders', 10);      // below 50
    pushFakeJobs('indicators', 100); // below 5000
    pushFakeJobs('default', 50);     // below 500

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'Horizon queue depth')
    );
});

it('fires horizon_queue_depth_positions when positions queue exceeds its tight per-queue threshold', function (): void {
    pushFakeJobs('positions', 60); // above 50
    pushFakeJobs('orders', 5);
    pushFakeJobs('indicators', 100);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'positions')
            && str_contains((string) ($n->title ?? ''), 'Horizon queue depth')
    );
});

it('does not fire on indicators queue at 4000 because its threshold absorbs the hourly direction-conclude burst', function (): void {
    pushFakeJobs('indicators', 4000); // below 5000

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'Horizon queue depth')
    );
});

it('fires distinct signals when multiple queues breach their thresholds in the same run', function (): void {
    pushFakeJobs('positions', 60);  // above 50
    pushFakeJobs('orders', 60);     // above 50
    pushFakeJobs('default', 600);   // above 500

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'positions')
            && str_contains((string) ($n->title ?? ''), 'Horizon queue depth')
    );
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'orders')
            && str_contains((string) ($n->title ?? ''), 'Horizon queue depth')
    );
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'default')
            && str_contains((string) ($n->title ?? ''), 'Horizon queue depth')
    );
});

it('source defines a per-queue threshold map and emits per-queue signals', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect($source)->toContain('HORIZON_QUEUE_DEPTH_THRESHOLDS');
    expect($source)->toContain('horizon_queue_depth_');
});
