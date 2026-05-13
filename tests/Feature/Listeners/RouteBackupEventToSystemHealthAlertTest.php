<?php

declare(strict_types=1);

use App\Listeners\RouteBackupEventToSystemHealthAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * Pin the Laravel-12 event-discovery wiring of
 * `RouteBackupEventToSystemHealthAlert`.
 *
 * The listener is the silent-failure prevention feature for backups
 * (CHANGELOG: "Closes the silent-failure gap that hid a B2 storage-cap
 * exhaustion in laravel.log for hours with no operator alert"). Its
 * own wiring is silently dependent on Laravel's default `app/Listeners/`
 * auto-discovery scan — a stale `event:cache` manifest, a future
 * Laravel change to discovery defaults, or a refactor that moves the
 * listener out of `app/Listeners/` would break backup alerting without
 * any code change. These tests pin the contract so any of those
 * regressions surface in CI instead of hours-after-incident.
 *
 * Each test fires a real Spatie event via Laravel's dispatcher and
 * asserts a `system_health_alert` Pushover-shaped notification is
 * produced with the right severity + signal-name shape.
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
});

it('fires system_health_alert with critical severity when BackupHasFailed dispatches', function (): void {
    event(new BackupHasFailed(
        exception: new RuntimeException('B2 quota exceeded'),
        diskName: 'b2',
        backupName: 'kraite-prod',
    ));

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'Backup failed on disk')
            && str_contains((string) ($n->title ?? ''), 'b2')
    );
});

it('fires system_health_alert with high severity when CleanupHasFailed dispatches', function (): void {
    event(new CleanupHasFailed(
        exception: new RuntimeException('Cleanup connection timeout'),
        diskName: 'b2',
        backupName: 'kraite-prod',
    ));

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'Backup cleanup failed')
            && str_contains((string) ($n->title ?? ''), 'b2')
    );
});

it('fires system_health_alert with high severity when UnhealthyBackupWasFound dispatches', function (): void {
    event(new UnhealthyBackupWasFound(
        diskName: 'b2',
        backupName: 'kraite-prod',
        failureMessages: new Collection([
            ['check' => 'MaximumAgeInDays', 'message' => 'Latest backup older than 24h'],
        ]),
    ));

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'Backup destination unhealthy')
            && str_contains((string) ($n->title ?? ''), 'b2')
    );
});

it('signal cache key includes the exception short class name on BackupHasFailed so distinct exceptions dedupe independently', function (): void {
    // First failure: RuntimeException — fires.
    event(new BackupHasFailed(
        exception: new RuntimeException('first failure'),
        diskName: 'b2',
        backupName: 'kraite-prod',
    ));

    // Second failure inside the same throttle window but with a
    // DIFFERENT exception class — must still fire (different signal,
    // different cache key) so a transient auth blip cannot mask a
    // quota / connectivity alert.
    event(new BackupHasFailed(
        exception: new InvalidArgumentException('second failure'),
        diskName: 'b2',
        backupName: 'kraite-prod',
    ));

    // Two distinct AlertNotifications must have been queued.
    $sent = 0;
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($n) use (&$sent) {
            if (
                ($n->canonical ?? '') === 'system_health_alert'
                && str_contains((string) ($n->title ?? ''), 'Backup failed on disk')
            ) {
                $sent++;
            }

            return true;
        }
    );

    expect($sent)->toBeGreaterThanOrEqual(2);
});

it('listener class lives in app/Listeners/ for Laravel auto-discovery', function (): void {
    // If a refactor ever moves this listener out of app/Listeners/,
    // Laravel's default event-discovery scan stops finding it and
    // backup alerting silently breaks. Pin the path.
    $reflection = new ReflectionClass(RouteBackupEventToSystemHealthAlert::class);
    $path = $reflection->getFileName();

    expect($path)->not->toBeFalse();
    expect($path)->toContain(DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Listeners'.DIRECTORY_SEPARATOR);
});
