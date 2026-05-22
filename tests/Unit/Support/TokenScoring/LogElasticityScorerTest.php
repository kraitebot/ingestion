<?php

declare(strict_types=1);

use Kraite\Core\Support\TokenScoring\LogElasticityScorer;

/*
 * LogElasticityScorer compresses the elasticity tail so a freak
 * 50× elasticity outlier doesn't single-handedly dwarf reasonable
 * candidates with strong correlation. Score formula:
 *
 *     score = log(1 + |elasticity|) × |correlation|
 *
 * Pure math; no model lookups, no I/O.
 */

test('zero elasticity produces zero score regardless of correlation', function (): void {
    expect(LogElasticityScorer::score(0.0, 1.0))->toBe(0.0);
    expect(LogElasticityScorer::score(0.0, 0.5))->toBe(0.0);
});

test('zero correlation produces zero score regardless of elasticity', function (): void {
    expect(LogElasticityScorer::score(50.0, 0.0))->toBe(0.0);
    expect(LogElasticityScorer::score(-100.0, 0.0))->toBe(0.0);
});

test('score grows monotonically with elasticity at fixed correlation', function (): void {
    $low = LogElasticityScorer::score(2.0, 0.8);
    $mid = LogElasticityScorer::score(10.0, 0.8);
    $high = LogElasticityScorer::score(50.0, 0.8);

    expect($low)->toBeLessThan($mid);
    expect($mid)->toBeLessThan($high);
});

test('elasticity sign is irrelevant — uses absolute value', function (): void {
    $positive = LogElasticityScorer::score(15.0, 0.9);
    $negative = LogElasticityScorer::score(-15.0, 0.9);

    expect($positive)->toBe($negative);
});

test('correlation sign is irrelevant — uses absolute value', function (): void {
    $positive = LogElasticityScorer::score(15.0, 0.9);
    $negative = LogElasticityScorer::score(15.0, -0.9);

    expect($positive)->toBe($negative);
});

test('compresses extreme elasticity so it does not dwarf reasonable candidates', function (): void {
    // Reasonable candidate: 5x elasticity, 0.9 correlation
    $reasonable = LogElasticityScorer::score(5.0, 0.9);

    // Extreme candidate: 100x elasticity, 0.4 correlation
    $extreme = LogElasticityScorer::score(100.0, 0.4);

    // Under raw multiplication, extreme would be 100×0.4=40 vs 5×0.9=4.5
    // (a 9× ratio favouring the extreme). Under log compression the
    // gap collapses — reasonable should remain competitive.
    $rawRatio = (100.0 * 0.4) / (5.0 * 0.9);
    $logRatio = $extreme / $reasonable;

    expect($logRatio)->toBeLessThan($rawRatio);
    expect($logRatio)->toBeLessThan(2.0);
});

test('log(1+x) base is consistent with natural log expectations', function (): void {
    // Sanity peg: score(e-1, 1) ≈ log(e) = 1.0
    $eMinusOne = M_E - 1.0;

    expect(LogElasticityScorer::score($eMinusOne, 1.0))->toBeGreaterThan(0.99);
    expect(LogElasticityScorer::score($eMinusOne, 1.0))->toBeLessThan(1.01);
});
