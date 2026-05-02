<?php

declare(strict_types=1);

use Kraite\Core\Support\TokenScoring\CorrelationStabilityWeight;

/*
 * CorrelationStabilityWeight returns a multiplier in [0, 1] that
 * penalises symbols whose rolling correlation with BTC is jittery.
 * A symbol with a steady 0.7 correlation across windows is more
 * trustworthy than one whose rolling correlation swings 0.3 → 0.95.
 *
 * Input is the standard deviation of the rolling-window correlations
 * for one timeframe (always non-negative; theoretical max ≈ 1.0
 * since correlation values themselves live in [-1, 1]).
 *
 * Graceful degrade: missing stability data → multiplier 1.0 so the
 * weight never punishes a symbol for the absence of input.
 */

test('null stability returns 1.0 — graceful degrade', function () {
    expect(CorrelationStabilityWeight::for(null))->toBe(1.0);
});

test('zero stability returns 1.0 — perfectly stable correlation', function () {
    expect(CorrelationStabilityWeight::for(0.0))->toBe(1.0);
});

test('low stability std-dev returns multiplier near 1.0', function () {
    $weight = CorrelationStabilityWeight::for(0.05);

    expect($weight)->toBeGreaterThan(0.85);
    expect($weight)->toBeLessThan(1.0);
});

test('high stability std-dev returns multiplier far below 1.0', function () {
    $weight = CorrelationStabilityWeight::for(0.5);

    expect($weight)->toBeLessThan(0.5);
});

test('weight is monotonically decreasing as std-dev grows', function () {
    $low = CorrelationStabilityWeight::for(0.05);
    $mid = CorrelationStabilityWeight::for(0.20);
    $high = CorrelationStabilityWeight::for(0.50);

    expect($low)->toBeGreaterThan($mid);
    expect($mid)->toBeGreaterThan($high);
});

test('weight clamps to zero — never goes negative', function () {
    expect(CorrelationStabilityWeight::for(2.0))->toBe(0.0);
    expect(CorrelationStabilityWeight::for(99.0))->toBe(0.0);
});

test('negative stability inputs treated as perfectly stable — defensive', function () {
    // Std-dev cannot be negative; if one slips through (numerical
    // edge / corrupt data) treat as perfect stability rather than
    // crash.
    expect(CorrelationStabilityWeight::for(-0.1))->toBe(1.0);
});
