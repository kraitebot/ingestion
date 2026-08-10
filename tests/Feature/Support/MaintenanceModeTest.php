<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Kraite\Core\Support\MaintenanceMode;

uses()->group('feature', 'support', 'maintenance');

beforeEach(function (): void {
    Cache::forget(MaintenanceMode::STEPS_DISPATCH_KEY);
    MaintenanceMode::clearPostWarmupRecovery();
});

afterEach(function (): void {
    MaintenanceMode::clearPostWarmupRecovery();
});

it('reports dispatch as not paused by default', function (): void {
    expect(MaintenanceMode::isStepsDispatchPaused())->toBeFalse()
        ->and(MaintenanceMode::stepsDispatchPauseInfo())->toBeNull();
});

it('engages the pause and reports the reason', function (): void {
    MaintenanceMode::pauseStepsDispatch(reason: 'OPTIMIZE TABLE breadcrumb rebuild');

    expect(MaintenanceMode::isStepsDispatchPaused())->toBeTrue();

    $info = MaintenanceMode::stepsDispatchPauseInfo();
    expect($info)->not->toBeNull()
        ->and($info['reason'])->toBe('OPTIMIZE TABLE breadcrumb rebuild')
        ->and($info['expires_in_seconds'])->toBe(MaintenanceMode::DEFAULT_TTL_SECONDS)
        ->and($info['paused_at'])->not->toBe('');
});

it('clears the pause when resume is called', function (): void {
    MaintenanceMode::pauseStepsDispatch(reason: 'test');

    expect(MaintenanceMode::isStepsDispatchPaused())->toBeTrue();

    MaintenanceMode::resumeStepsDispatch();

    expect(MaintenanceMode::isStepsDispatchPaused())->toBeFalse()
        ->and(MaintenanceMode::stepsDispatchPauseInfo())->toBeNull();
});

it('honours a custom TTL', function (): void {
    MaintenanceMode::pauseStepsDispatch(reason: 'custom-ttl', ttlSeconds: 90);

    $info = MaintenanceMode::stepsDispatchPauseInfo();
    expect($info)->not->toBeNull()
        ->and($info['expires_in_seconds'])->toBe(90);
});

it('starts a bounded recovery window when the ingestion server warms up', function (): void {
    config(['kraite.server_role' => 'ingestion']);
    Process::fake();

    expect(MaintenanceMode::isPostWarmupRecoveryActive())->toBeFalse();

    $this->artisan('kraite:warmup')->assertSuccessful();

    expect(MaintenanceMode::isPostWarmupRecoveryActive())->toBeTrue()
        ->and(MaintenanceMode::POST_WARMUP_RECOVERY_SECONDS)->toBe(600);
});

it('restarts every ingestion supervisor before bringing the application up', function (): void {
    config(['kraite.server_role' => 'ingestion']);
    Process::fake();

    $this->artisan('kraite:warmup')->assertSuccessful();

    foreach (['kraite-horizon', 'kraite-stream-binance-prices', 'kraite-stream-binance-user-data', 'kraite-dispatch-daemon', 'kraite-scheduler'] as $unit) {
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'sudo',
            '-n',
            'supervisorctl',
            'restart',
            $unit,
        ]);
    }
});

it('keeps the application in maintenance mode when a supervisor cannot restart', function (): void {
    config(['kraite.server_role' => 'ingestion']);

    Process::fake(fn (PendingProcess $process) => $process->command === [
        'sudo',
        '-n',
        'supervisorctl',
        'restart',
        'kraite-stream-binance-prices',
    ] ? Process::result(errorOutput: 'permission denied', exitCode: 1) : Process::result());

    $this->artisan('down')->assertSuccessful();

    try {
        $this->artisan('kraite:warmup')
            ->assertFailed()
            ->expectsOutputToContain('Could not restart kraite-stream-binance-prices');

        expect(app()->isDownForMaintenance())->toBeTrue();
    } finally {
        $this->artisan('up');
    }

    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === [
        'sudo',
        '-n',
        'supervisorctl',
        'restart',
        'kraite-horizon',
    ]);

    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === [
        'sudo',
        '-n',
        'supervisorctl',
        'restart',
        'kraite-stream-binance-prices',
    ]);
});
