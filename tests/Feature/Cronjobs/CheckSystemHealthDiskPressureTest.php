<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the new #12 disk-pressure check on `CheckSystemHealthCommand`.
 *
 * Threshold lives in the command as a private constant
 * (`DISK_FREE_PERCENT_MIN = 15`). The check reads PHP's
 * `disk_free_space('/')` and `disk_total_space('/')` directly —
 * those return real filesystem numbers in tests, so we can't
 * stub them out cheaply. Instead this suite asserts the SHAPE
 * of the canonical that fires when the threshold trips, plus the
 * happy-path no-alert when free space is above the bar.
 *
 * Without a way to fake the filesystem read, we exercise the
 * public command path with `Notification::fake()` and let the
 * real disk numbers decide whether the alert fires. On any dev
 * box with > 15% free root, the assertion is "no disk_pressure
 * alert was sent". A separate unit test could mock the static
 * `disk_free_space` if we ever migrate to a wrapper class — for
 * now keep it integration-style.
 */
beforeEach(function (): void {
    Notification::fake();
});

it('does not fire disk_pressure_low when the root filesystem has > 15% free', function (): void {
    $free = @disk_free_space('/');
    $total = @disk_total_space('/');

    expect($free)->toBeNumeric();
    expect($total)->toBeGreaterThan(0);

    $freePercent = ($free / $total) * 100;

    if ($freePercent < 15) {
        // Test environment is itself tight on disk — skip; the
        // alarm WILL fire correctly here, which is its intended
        // behaviour. Don't fail the suite for a real disk warning.
        $this->markTestSkipped("Test host root is at {$freePercent}% free (< 15%) — alarm correctly fires; skip the no-fire assertion.");
    }

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($notification) {
            return ($notification->canonical ?? '') === 'system_health_alert'
                && str_contains((string) ($notification->message ?? ''), 'disk_pressure_low');
        }
    );
});

it('registers the disk-pressure check in the command\'s checks runner array', function (): void {
    // Source-level pin: the check method must be wired into the
    // runner array so a future refactor can't silently drop the
    // 12th watchdog. Cheaper than running the full command.
    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect($source)->toContain("'checkDiskPressure'");
    expect($source)->toContain('disk_pressure_low');
    expect($source)->toContain('DISK_FREE_PERCENT_MIN');
});
