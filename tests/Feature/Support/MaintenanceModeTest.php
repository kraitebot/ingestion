<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Kraite\Core\Support\MaintenanceMode;

uses()->group('feature', 'support', 'maintenance');

beforeEach(function () {
    Cache::forget(MaintenanceMode::STEPS_DISPATCH_KEY);
});

it('reports dispatch as not paused by default', function () {
    expect(MaintenanceMode::isStepsDispatchPaused())->toBeFalse()
        ->and(MaintenanceMode::stepsDispatchPauseInfo())->toBeNull();
});

it('engages the pause and reports the reason', function () {
    MaintenanceMode::pauseStepsDispatch(reason: 'OPTIMIZE TABLE breadcrumb rebuild');

    expect(MaintenanceMode::isStepsDispatchPaused())->toBeTrue();

    $info = MaintenanceMode::stepsDispatchPauseInfo();
    expect($info)->not->toBeNull()
        ->and($info['reason'])->toBe('OPTIMIZE TABLE breadcrumb rebuild')
        ->and($info['expires_in_seconds'])->toBe(MaintenanceMode::DEFAULT_TTL_SECONDS)
        ->and($info['paused_at'])->not->toBe('');
});

it('clears the pause when resume is called', function () {
    MaintenanceMode::pauseStepsDispatch(reason: 'test');

    expect(MaintenanceMode::isStepsDispatchPaused())->toBeTrue();

    MaintenanceMode::resumeStepsDispatch();

    expect(MaintenanceMode::isStepsDispatchPaused())->toBeFalse()
        ->and(MaintenanceMode::stepsDispatchPauseInfo())->toBeNull();
});

it('honours a custom TTL', function () {
    MaintenanceMode::pauseStepsDispatch(reason: 'custom-ttl', ttlSeconds: 90);

    $info = MaintenanceMode::stepsDispatchPauseInfo();
    expect($info)->not->toBeNull()
        ->and($info['expires_in_seconds'])->toBe(90);
});
