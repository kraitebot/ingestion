<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the stuck-maintenance-mode alert on `CheckSystemHealthCommand`.
 *
 * 2026-07-02 incident: athena's release warmup never ran `artisan up`,
 * so the box sat in maintenance mode for two days. Laravel's scheduler
 * skips every event while the app is down — including this watchdog —
 * so the entire cron chain (listen-key keepalive, sync fallback, DB
 * backups) died silently; the only visible symptom was a Binance
 * `listenKeyExpired` page every 70 minutes.
 *
 * Post-fix, the health command is scheduled `evenInMaintenanceMode()`
 * and, while the app is down, performs exactly ONE check: "has this box
 * been in maintenance longer than the threshold". Every other check is
 * skipped in that window so a normal deploy's maintenance period never
 * produces transient pages.
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

    @unlink(storage_path('framework/down'));
    @unlink(storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log'));
    clearstatcache();
});

afterEach(function (): void {
    @unlink(storage_path('framework/down'));
    @unlink(storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log'));
    clearstatcache();
});

it('fires maintenance_mode_stuck when the box has been down past the threshold', function (): void {
    $path = storage_path('framework/down');
    file_put_contents($path, json_encode(['retry' => 60, 'status' => 503]));
    touch($path, time() - 46 * 60);
    clearstatcache();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'maintenance mode')
    );
});

it('stays silent while maintenance is fresh — a normal deploy window', function (): void {
    $path = storage_path('framework/down');
    file_put_contents($path, json_encode(['retry' => 60, 'status' => 503]));
    clearstatcache();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNothingSent();
});

it('runs only the stuck-maintenance check while the app is down', function (): void {
    // A non-empty deadletter file fires `user_data_deadletter_active`
    // on a normal pass — proven by CheckSystemHealthDeadLetterTest.
    // While the app is down, that check (and every other one) must be
    // skipped so deploy windows never produce transient pages.
    $deadletter = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');
    file_put_contents($deadletter, json_encode(['e' => 'ORDER_TRADE_UPDATE']).PHP_EOL);

    $down = storage_path('framework/down');
    file_put_contents($down, json_encode(['retry' => 60, 'status' => 503]));
    clearstatcache();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'dead-lettered frame')
    );
});

it('keeps the full check pass when the app is up', function (): void {
    $deadletter = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');
    file_put_contents($deadletter, json_encode(['e' => 'ORDER_TRADE_UPDATE']).PHP_EOL);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'dead-lettered frame')
    );
});

it('schedules the health watchdog to run even in maintenance mode', function (): void {
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->toMatch(
        '/kraite:cron-check-system-health\'\)[\s\S]{0,200}?->evenInMaintenanceMode\(\)/'
    );
});
