<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Health\SystemHealthCheckType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
    Illuminate\Support\Once::flush();
    Cache::forget('system_health_alert-signal:user_data_stream_record_only_binance');

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ],
    );
});

it('alerts when Binance user-data events are configured as record-only', function (): void {
    config()->set('kraite.user_data_stream.binance.dispatched_executions', []);

    expect(app(CheckSystemHealthCommand::class)->checkUserDataStreamDispatchMode())->toBe(1);

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification): bool => ($notification->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($notification->title ?? ''), 'record-only'),
    );
});

it('does not alert when Binance user-data dispatch is enabled', function (): void {
    config()->set('kraite.user_data_stream.binance.dispatched_executions', ['TRADE']);

    expect(app(CheckSystemHealthCommand::class)->checkUserDataStreamDispatchMode())->toBe(0);

    Notification::assertNothingSent();
});

it('runs the user-data dispatch-mode check in every standard health pass', function (): void {
    expect(SystemHealthCheckType::standardCases())
        ->toContain(SystemHealthCheckType::UserDataStreamDispatchMode);

    expect(file_get_contents(base_path('.env.example')))
        ->toContain('USER_DATA_STREAM_BINANCE_DISPATCHED_EXECUTIONS=TRADE,AMENDMENT,CANCELED,EXPIRED,REJECTED,');
});
