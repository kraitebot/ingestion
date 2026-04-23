<?php

declare(strict_types=1);

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\ValidationIndicator;
use Kraite\Core\Indicators\RefreshData\PivotPointsIndicator;

/**
 * PivotPointsIndicator is a ValidationIndicator that always returns true.
 *
 * Rationale (per design): pivot levels don't decide a direction and don't
 * fail a timeframe — they are data we store for the selection-phase S/R
 * gate. By making the indicator's `isValid()` always true, it plugs into
 * the existing conclude-indicators pipeline without ever invalidating a
 * timeframe or contributing to a direction vote. Its only job is to
 * bring pivot data into `indicator_histories` + `indicators_values` so
 * the finalization step can pluck the levels out.
 */
it('extends BaseIndicator', function (): void {
    $reflection = new ReflectionClass(PivotPointsIndicator::class);

    expect($reflection->isSubclassOf(BaseIndicator::class))->toBeTrue();
});

it('implements the ValidationIndicator contract', function (): void {
    $reflection = new ReflectionClass(PivotPointsIndicator::class);

    expect($reflection->implementsInterface(ValidationIndicator::class))->toBeTrue();
});

it('targets the TAAPI pivotpoints endpoint', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(PivotPointsIndicator::class))->getFileName()
    );

    expect($source)->toContain("'pivotpoints'");
});

it('isValid() always returns true regardless of data', function (): void {
    $reflection = new ReflectionClass(PivotPointsIndicator::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    // Empty data → still valid
    $dataProp = new ReflectionProperty(BaseIndicator::class, 'data');
    $dataProp->setValue($instance, []);
    expect($instance->isValid())->toBeTrue();

    // With payload → still valid
    $dataProp->setValue($instance, ['r1' => 100, 's1' => 90]);
    expect($instance->isValid())->toBeTrue();
});

it('conclusion() always returns true (never blocks a timeframe)', function (): void {
    $reflection = new ReflectionClass(PivotPointsIndicator::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $dataProp = new ReflectionProperty(BaseIndicator::class, 'data');
    $dataProp->setValue($instance, []);

    // Must be truthy — matches how ConcludeSymbolDirectionAtTimeframeJob
    // treats ValidationIndicator results (only 0/false/'0' invalidates).
    expect($instance->conclusion())->toBeTrue();
});
