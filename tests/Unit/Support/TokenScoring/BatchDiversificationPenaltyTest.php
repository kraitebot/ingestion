<?php

declare(strict_types=1);

use Kraite\Core\Support\TokenScoring\BatchDiversificationPenalty;

/*
 * BatchDiversificationPenalty downscales a candidate's score when
 * its 1-day BTC correlation profile is too close to that of any
 * symbol already picked in the SAME batch (same call to
 * assignTokensToPositions). Forces structural diversity within a
 * single position-creation cycle so a 6-LONG book is not 6
 * essentially-identical bets on BTC up.
 *
 * Comparison key: btc_correlation_rolling['1d']. Two LONG candidates
 * both at 1d-correlation 0.95 are de-facto the same trade; one of
 * the two should be deprioritised.
 *
 * Returns a multiplier in [0, 1]. Missing or empty batch → 1.0.
 */

test('empty already-picked batch returns 1.0 — no penalty', function (): void {
    expect(BatchDiversificationPenalty::for(0.95, []))->toBe(1.0);
});

test('candidate far from all picked symbols returns 1.0', function (): void {
    $picked = [0.95, 0.92];
    $candidate = 0.30;

    expect(BatchDiversificationPenalty::for($candidate, $picked))->toBe(1.0);
});

test('candidate inside threshold of any picked symbol returns penalty', function (): void {
    $picked = [0.95];
    $candidate = 0.93;

    $penalty = BatchDiversificationPenalty::for($candidate, $picked);

    expect($penalty)->toBeLessThan(1.0);
    expect($penalty)->toBeGreaterThan(0.0);
});

test('candidate exactly matching a picked symbol returns minimum penalty', function (): void {
    $picked = [0.95];
    $candidate = 0.95;

    $penalty = BatchDiversificationPenalty::for($candidate, $picked);

    expect($penalty)->toBeLessThan(0.6);
});

test('candidate sign opposite to picked is treated as different — no penalty', function (): void {
    // A LONG ladder of LONG slots typically picks all positively
    // BTC-correlated tokens. A negatively-correlated candidate (one
    // that hedges BTC) is structurally distinct and should NOT be
    // penalised even if its absolute correlation is similar.
    $picked = [0.90];
    $candidate = -0.90;

    expect(BatchDiversificationPenalty::for($candidate, $picked))->toBe(1.0);
});

test('penalty fires on the closest match — best match across batch determines weight', function (): void {
    // Picked set has one similar (0.94) and one very different (-0.50)
    // entry. Penalty should reflect the SIMILAR one — diversity is
    // about how "redundant" the candidate is with ANYTHING already
    // picked, not the average.
    $closeMatch = BatchDiversificationPenalty::for(0.93, [0.94]);
    $mixedBatch = BatchDiversificationPenalty::for(0.93, [0.94, -0.50]);

    expect($mixedBatch)->toBe($closeMatch);
});

test('threshold parameter controls penalty trigger distance', function (): void {
    // With a tighter threshold (0.05) candidate at 0.10 from picked
    // is outside → no penalty. With a looser threshold (0.20) it's
    // inside → penalty.
    $tight = BatchDiversificationPenalty::for(0.85, [0.95], threshold: 0.05);
    $loose = BatchDiversificationPenalty::for(0.85, [0.95], threshold: 0.20);

    expect($tight)->toBe(1.0);
    expect($loose)->toBeLessThan(1.0);
});
