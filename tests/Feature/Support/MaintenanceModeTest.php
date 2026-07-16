<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
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

    expect(MaintenanceMode::isPostWarmupRecoveryActive())->toBeFalse();

    $this->artisan('kraite:warmup')->assertSuccessful();

    expect(MaintenanceMode::isPostWarmupRecoveryActive())->toBeTrue()
        ->and(MaintenanceMode::POST_WARMUP_RECOVERY_SECONDS)->toBe(600);
});
