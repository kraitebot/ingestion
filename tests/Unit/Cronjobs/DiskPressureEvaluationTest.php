<?php

declare(strict_types=1);

use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;

/**
 * Pin the disk-pressure evaluation logic via a pure-function extract.
 *
 * Pre-fix, `checkDiskPressure()` inlined `disk_free_space()` /
 * `disk_total_space()` calls plus threshold math plus alert formatting,
 * making the alert path untestable except by running the whole command
 * on a host with low disk. The existing
 * `CheckSystemHealthDiskPressureTest` only covered the no-alert path
 * on a healthy machine + a source-string assertion.
 *
 * Post-fix, the math + formatting moved into a pure
 * `evaluateDiskPressure(int|float|false $free, int|float|false $total,
 * int $thresholdPercent): ?array` function. Returns null when no alert
 * (healthy / unreadable / corrupt total), or a structured array with
 * the alert detail fields. Tests pin: low-disk fires, healthy doesn't,
 * permission-failure silent-skips, threshold boundary, GiB formatting.
 *
 * Same silent-failure prevention pattern as the backup-listener pin
 * (review-9 Finding 7) — a regression in the threshold math or the
 * `disk_free_space === false` handling that silently swallows a real
 * low-disk state is exactly the kind of failure operators need pinned.
 */
function callEvaluateDiskPressure(int|float|false $free, int|float|false $total, int $thresholdPercent = 15): ?array
{
    $reflection = new ReflectionClass(CheckSystemHealthCommand::class);
    $method = $reflection->getMethod('evaluateDiskPressure');
    $method->setAccessible(true);

    return $method->invoke(null, $free, $total, $thresholdPercent);
}

it('returns null when free percent is at or above the threshold (healthy)', function (): void {
    // 50 GiB free of 100 GiB total = 50% free, well above 15% threshold
    $result = callEvaluateDiskPressure(
        free: 50 * (1024 ** 3),
        total: 100 * (1024 ** 3),
    );

    expect($result)->toBeNull();
});

it('returns null exactly at the threshold (15% free at 15% threshold is healthy)', function (): void {
    $result = callEvaluateDiskPressure(
        free: 15 * (1024 ** 3),
        total: 100 * (1024 ** 3),
    );

    expect($result)->toBeNull();
});

it('returns alert payload when free percent is below threshold', function (): void {
    // 5 GiB free of 100 GiB total = 5% free, below 15% threshold
    $result = callEvaluateDiskPressure(
        free: 5 * (1024 ** 3),
        total: 100 * (1024 ** 3),
    );

    expect($result)->toBeArray();
    expect($result['free_gib'])->toBe('5.00');
    expect($result['total_gib'])->toBe('100.00');
    expect($result['free_percent_str'])->toBe('5.0');
});

it('returns alert payload just below threshold boundary (14% free at 15% threshold)', function (): void {
    $result = callEvaluateDiskPressure(
        free: 14 * (1024 ** 3),
        total: 100 * (1024 ** 3),
    );

    expect($result)->toBeArray();
    expect($result['free_percent_str'])->toBe('14.0');
});

it('returns null when disk_free_space returned false (permission-failure silent-skip)', function (): void {
    $result = callEvaluateDiskPressure(
        free: false,
        total: 100 * (1024 ** 3),
    );

    expect($result)->toBeNull();
});

it('returns null when disk_total_space returned false', function (): void {
    $result = callEvaluateDiskPressure(
        free: 50 * (1024 ** 3),
        total: false,
    );

    expect($result)->toBeNull();
});

it('returns null when total is zero (corrupt result, would divide by zero)', function (): void {
    $result = callEvaluateDiskPressure(
        free: 50 * (1024 ** 3),
        total: 0,
    );

    expect($result)->toBeNull();
});

it('returns null when total is negative (corrupt result)', function (): void {
    $result = callEvaluateDiskPressure(
        free: 50 * (1024 ** 3),
        total: -1,
    );

    expect($result)->toBeNull();
});

it('respects custom threshold percent (5% threshold, 10% free is healthy)', function (): void {
    $result = callEvaluateDiskPressure(
        free: 10 * (1024 ** 3),
        total: 100 * (1024 ** 3),
        thresholdPercent: 5,
    );

    expect($result)->toBeNull();
});

it('respects custom threshold percent (50% threshold, 30% free fires)', function (): void {
    $result = callEvaluateDiskPressure(
        free: 30 * (1024 ** 3),
        total: 100 * (1024 ** 3),
        thresholdPercent: 50,
    );

    expect($result)->toBeArray();
    expect($result['free_percent_str'])->toBe('30.0');
});
