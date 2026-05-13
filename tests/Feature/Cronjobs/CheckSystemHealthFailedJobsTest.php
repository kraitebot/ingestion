<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the split failed_jobs alert on `CheckSystemHealthCommand`.
 *
 * Pre-fix, `checkFailedJobsOverflow()` was a raw lifetime count of
 * `failed_jobs` rows against a single threshold of 10. Two operational
 * problems:
 *  - operator investigates yesterday's incident, doesn't prune → alert
 *    keeps firing on stale historical rows (nothing currently broken)
 *  - large historical accumulation drowns out a current 5-failure
 *    burst because both produce the same generic "table > 10 rows"
 *    Pushover with no exception / job-class context
 *
 * Post-fix, the check splits into two distinct signals:
 *  - `failed_jobs_recent_burst` — rolling 1h count above
 *    `FAILED_JOBS_RECENT_THRESHOLD`. Includes top exception class +
 *    top job class so the Pushover names the actual root cause.
 *  - `failed_jobs_capacity` — total count above
 *    `FAILED_JOBS_CAPACITY_THRESHOLD`. Different operator action:
 *    prune the table, not investigate a fresh failure.
 *
 * Each signal has its own cache key so they dedupe independently.
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

    DB::table('failed_jobs')->truncate();
});

afterEach(function (): void {
    DB::table('failed_jobs')->truncate();
});

function seedFailedJob(Carbon\CarbonInterface $failedAt, string $exceptionClass, string $jobClass): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => $jobClass,
            'data' => ['commandName' => $jobClass],
        ]),
        'exception' => "{$exceptionClass}: simulated failure for test\n#0 stack-trace-line",
        'failed_at' => $failedAt,
    ]);
}

it('does not fire any failed_jobs alert when the table is empty', function (): void {
    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'failed_jobs')
    );
});

it('does not fire failed_jobs_recent_burst when recent failures are below threshold', function (): void {
    // 4 failures in the last hour — below threshold of 5
    for ($i = 0; $i < 4; $i++) {
        seedFailedJob(now()->subMinutes(10 + $i), 'RuntimeException', 'App\\Jobs\\TestJob');
    }

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'recent burst')
    );
});

it('fires failed_jobs_recent_burst with top exception + job class when 1h count exceeds threshold', function (): void {
    // 6 failures in the last hour — above threshold of 5
    for ($i = 0; $i < 6; $i++) {
        seedFailedJob(now()->subMinutes(10 + $i), 'RuntimeException', 'App\\Jobs\\PlaceMarketOrderJob');
    }

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'recent burst')
            && (
                str_contains((string) ($n->message ?? ''), 'RuntimeException')
                || str_contains((string) ($n->additionalParameters['detail'] ?? ''), 'RuntimeException')
                || str_contains(json_encode($n->additionalParameters ?? []), 'RuntimeException')
            )
    );
});

it('does not fire failed_jobs_recent_burst when failures are old (>1h)', function (): void {
    // 50 failures all >1h old — should not trigger the recent-burst alert
    for ($i = 0; $i < 50; $i++) {
        seedFailedJob(now()->subDays(2)->subMinutes($i), 'RuntimeException', 'App\\Jobs\\TestJob');
    }

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'recent burst')
    );
});

it('fires failed_jobs_capacity when total count exceeds capacity threshold even if all rows are old', function (): void {
    // 1100 old failures — above capacity threshold of 1000
    $rows = [];
    for ($i = 0; $i < 1100; $i++) {
        $rows[] = [
            'uuid' => (string) Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\TestJob']),
            'exception' => "RuntimeException: old failure\n#0 trace",
            'failed_at' => now()->subDays(7),
        ];
    }
    DB::table('failed_jobs')->insert($rows);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'capacity')
    );
});

it('source defines split thresholds + split signal canonicals', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect($source)->toContain('FAILED_JOBS_RECENT_THRESHOLD');
    expect($source)->toContain('FAILED_JOBS_CAPACITY_THRESHOLD');
    expect($source)->toContain('failed_jobs_recent_burst');
    expect($source)->toContain('failed_jobs_capacity');
    expect($source)->not->toContain('FAILED_JOBS_THRESHOLD = 10');
});
