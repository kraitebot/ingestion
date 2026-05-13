<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the user-data daemon dead-letter alert on `CheckSystemHealthCommand`.
 *
 * `StreamBinanceUserDataCommand::stashFrameToDisk` writes JSONL lines to
 * `storage/logs/user-data-deadletter-YYYY-MM-DD.log` whenever Step::create
 * throws inside the ReactPHP message handler. Pre-fix, the failure was
 * only visible in the `user-data` log channel — operators had to grep.
 * Post-fix, this watchdog converts a non-empty deadletter file into a
 * Pushover-grade `user_data_deadletter_active` signal via the existing
 * `system_health_alert` notification.
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

    // Always start each test with no deadletter file present.
    $path = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');
    if (is_file($path)) {
        @unlink($path);
    }
});

afterEach(function (): void {
    $path = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');
    if (is_file($path)) {
        @unlink($path);
    }
});

it('does not fire user_data_deadletter_active when the file does not exist', function (): void {
    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'dead-lettered frame')
    );
});

it('does not fire user_data_deadletter_active when the file exists but is empty', function (): void {
    $path = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');
    @file_put_contents($path, '');

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'dead-lettered frame')
    );
});

it('fires user_data_deadletter_active when the file has at least one entry', function (): void {
    $path = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');
    $entry = json_encode([
        'ts' => now()->toIso8601String(),
        'account_id' => 1,
        'account_name' => 'test-account',
        'error' => 'Step::create threw',
        'payload' => ['e' => 'ORDER_TRADE_UPDATE'],
    ]).PHP_EOL;
    @file_put_contents($path, $entry);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'dead-lettered frame')
    );
});

it('registers the deadletter check in the command\'s checks runner array', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect($source)->toContain("'checkUserDataDeadLetters'");
    expect($source)->toContain('user_data_deadletter_active');
    expect($source)->toContain('user-data-deadletter-');
});
