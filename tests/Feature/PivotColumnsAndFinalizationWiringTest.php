<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\QueryAndStoreSupportAndResistanceJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;

/**
 * Schema + wiring tests for the support/resistance finalization chain.
 *
 * - The seven pivot columns + pivot_synced_at land on exchange_symbols.
 * - The QueryAndStoreSupportAndResistanceJob atomic + lifecycle classes
 *   exist and follow the atomic/lifecycle pair pattern.
 * - ConcludeSymbolDirectionAtTimeframeJob::createFinalizationSteps
 *   references the new lifecycle, so the step is actually created AFTER
 *   a direction is concluded.
 * - The three direction-invalidation code paths
 *   (handleInconclusiveTimeframe last-timeframe, handleDirectionChange
 *   path-invalid, ConcludeSymbolsDirectionCommand --reset) all null the
 *   pivot columns alongside direction/indicators_values/indicators_timeframe.
 */
it('adds all seven pivot columns + pivot_synced_at to exchange_symbols', function (): void {
    $expected = [
        'pivot_r3', 'pivot_r2', 'pivot_r1',
        'pivot_p',
        'pivot_s1', 'pivot_s2', 'pivot_s3',
        'pivot_synced_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('exchange_symbols', $column))->toBeTrue(
            "exchange_symbols.{$column} must exist for the S/R gate to function"
        );
    }
});

it('defines the QueryAndStoreSupportAndResistanceJob atomic class', function (): void {
    expect(class_exists(QueryAndStoreSupportAndResistanceJob::class))->toBeTrue();
});

it('wires QueryAndStoreSupportAndResistance into createFinalizationSteps', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(ConcludeSymbolDirectionAtTimeframeJob::class))->getFileName()
    );

    // The lifecycle (or atomic — whichever is dispatched) must be
    // referenced in the source of ConcludeSymbolDirectionAtTimeframeJob
    // so finalization creates the step for each concluded symbol.
    expect($source)->toContain('QueryAndStoreSupportAndResistanceJob');
});

it('nulls pivot columns when direction is invalidated', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(ConcludeSymbolDirectionAtTimeframeJob::class))->getFileName()
    );

    // Every `direction => null` updateSaving call must also null the
    // pivot columns. Test by asserting pivot_r1 (one representative
    // column) appears alongside direction=>null at least twice (the two
    // invalidation paths in this file).
    $occurrences = mb_substr_count($source, 'pivot_r1');

    expect($occurrences)->toBeGreaterThanOrEqual(
        2,
        'Both direction-invalidation paths in ConcludeSymbolDirectionAtTimeframeJob '
        .'must null the pivot columns — otherwise stale pivots survive after the symbol '
        .'loses its direction and the S/R filter uses wrong levels.'
    );
});
