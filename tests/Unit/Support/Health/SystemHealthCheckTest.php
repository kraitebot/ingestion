<?php

declare(strict_types=1);

use Kraite\Core\Support\Health\Contracts\SystemHealthProbe;
use Kraite\Core\Support\Health\SystemHealthCheck;
use Kraite\Core\Support\Health\SystemHealthCheckType;

it('runs a typed system-health probe without a callback or dynamic method name', function (): void {
    $probe = Mockery::mock(SystemHealthProbe::class);
    $probe->expects('checkDatabaseConnection')->once()->andReturn(2);

    $check = new SystemHealthCheck(SystemHealthCheckType::DatabaseConnection, $probe);

    expect($check->name())->toBe('checkDatabaseConnection')
        ->and($check->run())->toBe(2);
});

it('owns dispatcher-recovery metadata in the check vocabulary', function (): void {
    $dispatcherDependent = collect(SystemHealthCheckType::standardCases())
        ->filter(static fn (SystemHealthCheckType $type): bool => $type->isDispatcherDependent())
        ->pluck('value')
        ->values()
        ->all();

    expect($dispatcherDependent)->toBe([
        'checkIndicatorFreshness',
        'checkAccountBalanceFreshness',
    ])
        ->and(SystemHealthCheckType::standardCases())
        ->not->toContain(SystemHealthCheckType::MaintenanceModeStuck);
});
