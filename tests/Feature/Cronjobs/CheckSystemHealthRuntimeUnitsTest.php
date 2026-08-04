<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Health\SystemHealthCheckType;
use Kraite\Core\Support\MaintenanceMode;

const RUNTIME_UNITS_HOST = 'runtime-units-test';

beforeEach(function (): void {
    $this->sharedHealthResourceLock = acquireKraiteTestLock('shared-system-health-resources');

    config([
        'kraite.fleet_metrics.hostname' => RUNTIME_UNITS_HOST,
        'kraite.notifications_enabled' => true,
    ]);

    Notification::fake();
    MaintenanceMode::clearPostWarmupRecovery();
    Cache::forget('system_health_alert-signal:runtime_units_unhealthy_'.RUNTIME_UNITS_HOST);

    try {
        Redis::connection('fleet')->del('kraite:fleet:'.RUNTIME_UNITS_HOST);
    } catch (Throwable $exception) {
        test()->markTestSkipped("Redis unavailable in test env: {$exception->getMessage()}");
    }
});

afterEach(function (): void {
    MaintenanceMode::clearPostWarmupRecovery();
    Cache::forget('system_health_alert-signal:runtime_units_unhealthy_'.RUNTIME_UNITS_HOST);

    try {
        Redis::connection('fleet')->del('kraite:fleet:'.RUNTIME_UNITS_HOST);
    } catch (Throwable) {
    }

    releaseKraiteTestLock($this->sharedHealthResourceLock ?? null);
});

function writeRuntimeUnitSnapshot(array $units, ?string $reportedAt = null): void
{
    Redis::connection('fleet')->setex(
        'kraite:fleet:'.RUNTIME_UNITS_HOST,
        300,
        json_encode([
            'hostname' => RUNTIME_UNITS_HOST,
            'reported_at' => $reportedAt ?? now()->toIso8601String(),
            'units' => $units,
        ], JSON_THROW_ON_ERROR),
    );
}

it('alerts with every non-running runtime unit from the fresh PHP fleet snapshot', function (): void {
    writeRuntimeUnitSnapshot([
        'kraite-horizon' => 'RUNNING',
        'kraite-scheduler' => 'STOPPED',
        'kraite-stream-binance-prices' => 'FATAL',
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkRuntimeUnitStatus())->toBe(1);

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function (AlertNotification $notification): bool {
            $detail = (string) ($notification->pushoverMessage ?? $notification->message);

            return ($notification->canonical ?? '') === 'system_health_alert'
                && str_contains((string) ($notification->title ?? ''), RUNTIME_UNITS_HOST)
                && str_contains($detail, 'kraite-scheduler=STOPPED')
                && str_contains($detail, 'kraite-stream-binance-prices=FATAL')
                && ! str_contains($detail, 'kraite-horizon=RUNNING');
        },
    );
});

it('stays silent when every reported runtime unit is running', function (): void {
    writeRuntimeUnitSnapshot([
        'kraite-horizon' => 'RUNNING',
        'kraite-scheduler' => 'RUNNING',
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkRuntimeUnitStatus())->toBe(0);

    Notification::assertNothingSent();
});

it('leaves missing fleet snapshots to the existing fleet silence check', function (): void {
    expect(app(CheckSystemHealthCommand::class)->checkRuntimeUnitStatus())->toBe(0);

    Notification::assertNothingSent();
});

it('leaves stale runtime-unit snapshots to the existing fleet silence check', function (): void {
    writeRuntimeUnitSnapshot(
        ['kraite-scheduler' => 'STOPPED'],
        now()->subSeconds(721)->toIso8601String(),
    );

    expect(app(CheckSystemHealthCommand::class)->checkRuntimeUnitStatus())->toBe(0);

    Notification::assertNothingSent();
});

it('leaves snapshots with an unreadable timestamp to the existing fleet silence check', function (): void {
    writeRuntimeUnitSnapshot(
        ['kraite-scheduler' => 'STOPPED'],
        'not-a-timestamp',
    );

    expect(app(CheckSystemHealthCommand::class)->checkRuntimeUnitStatus())->toBe(0);

    Notification::assertNothingSent();
});

it('runs the runtime unit check in every standard health pass', function (): void {
    expect(SystemHealthCheckType::standardCases())
        ->toContain(SystemHealthCheckType::RuntimeUnitStatus);
});

it('suppresses the transitional runtime unit snapshot during post-warmup recovery', function (): void {
    writeRuntimeUnitSnapshot([
        'kraite-horizon' => 'RUNNING',
        'kraite-scheduler' => 'STOPPED',
    ]);
    MaintenanceMode::startPostWarmupRecovery();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => str_contains(
            (string) ($notification->title ?? ''),
            RUNTIME_UNITS_HOST,
        ),
    );

    MaintenanceMode::clearPostWarmupRecovery();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => str_contains(
            (string) ($notification->title ?? ''),
            RUNTIME_UNITS_HOST,
        ),
    );
});
