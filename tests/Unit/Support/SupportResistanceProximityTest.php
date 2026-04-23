<?php

declare(strict_types=1);

use Kraite\Core\Support\SupportResistanceProximity;

/**
 * Pure-math tests for the selection-phase proximity multiplier.
 *
 * The multiplier scales the BTC-bias score during token selection so
 * tokens trading "in the middle" of their R1-S1 range retain full
 * score and tokens approaching the wrong-side level for their
 * direction get deprioritised down toward zero. Breakouts through R3
 * (LONG) or S3 (SHORT) restore full score as direction-matched
 * continuation; breakouts against direction return zero.
 *
 * Math: position_in_range = (mark - s1) / (r1 - s1)
 *   - LONG penalty band: position > 1 - safe_zone
 *     multiplier = max(0, (1 - position) / safe_zone)
 *   - SHORT penalty band: position < safe_zone
 *     multiplier = max(0, position / safe_zone)
 *   - Beyond R3 / S3: direction-aware hard accept or hard zero.
 *   - Missing data: multiplier = 1.0 (graceful degrade).
 */
it('returns 1.0 for a LONG sitting in the middle of the range', function (): void {
    // s1=100, r1=110 → range width 10. mark=105 → position=0.5 (dead middle).
    $mult = SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '105',
        r1: '110', r3: '120',
        s1: '100', s3: '90',
    );

    expect($mult)->toBe(1.0);
});

it('returns 1.0 for a SHORT sitting in the middle of the range', function (): void {
    $mult = SupportResistanceProximity::computeMultiplier(
        direction: 'SHORT',
        markPrice: '105',
        r1: '110', r3: '120',
        s1: '100', s3: '90',
    );

    expect($mult)->toBe(1.0);
});

it('penalises a LONG approaching R1 linearly down to 0.0', function (): void {
    // position = 0.85 (15 % from R1), safe_zone=0.20 → (1-0.85)/0.20 = 0.75
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '108.5',
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(0.75);

    // position = 0.95 → (1-0.95)/0.20 = 0.25
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '109.5',
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(0.25);

    // position = 1.0 (at R1 exactly) → (1-1)/0.20 = 0.0
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '110',
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(0.0);
});

it('penalises a SHORT approaching S1 linearly down to 0.0', function (): void {
    // position = 0.15 → 0.15/0.20 = 0.75
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'SHORT',
        markPrice: '101.5',
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(0.75);

    // position = 0.05 → 0.05/0.20 = 0.25
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'SHORT',
        markPrice: '100.5',
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(0.25);

    // position = 0.0 (at S1 exactly) → 0.0
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'SHORT',
        markPrice: '100',
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(0.0);
});

it('accepts a LONG breakout above R3 at full score (direction-matched continuation)', function (): void {
    $mult = SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '125', // above R3=120
        r1: '110', r3: '120', s1: '100', s3: '90',
    );

    expect($mult)->toBe(1.0);
});

it('rejects a SHORT when price has broken up through R3 (against direction)', function (): void {
    $mult = SupportResistanceProximity::computeMultiplier(
        direction: 'SHORT',
        markPrice: '125', // above R3=120, but we are SHORT
        r1: '110', r3: '120', s1: '100', s3: '90',
    );

    expect($mult)->toBe(0.0);
});

it('accepts a SHORT breakout below S3 at full score (direction-matched continuation)', function (): void {
    $mult = SupportResistanceProximity::computeMultiplier(
        direction: 'SHORT',
        markPrice: '85', // below S3=90
        r1: '110', r3: '120', s1: '100', s3: '90',
    );

    expect($mult)->toBe(1.0);
});

it('rejects a LONG when price has broken down through S3 (against direction)', function (): void {
    $mult = SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '85', // below S3=90, but we are LONG
        r1: '110', r3: '120', s1: '100', s3: '90',
    );

    expect($mult)->toBe(0.0);
});

it('returns 1.0 when any pivot level is missing (graceful degrade)', function (): void {
    // No pivot data yet — do not penalise at all. Symbol still eligible.
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '105',
        r1: null, r3: null, s1: null, s3: null,
    ))->toBe(1.0);

    // Partial data also → 1.0
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '105',
        r1: '110', r3: null, s1: '100', s3: '90',
    ))->toBe(1.0);
});

it('returns 1.0 when mark_price is missing', function (): void {
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: null,
        r1: '110', r3: '120', s1: '100', s3: '90',
    ))->toBe(1.0);
});

it('returns 1.0 when R1 and S1 collapse to the same value (degenerate range)', function (): void {
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '105',
        r1: '100', r3: '120', s1: '100', s3: '90',
    ))->toBe(1.0);
});

it('respects a custom safe_zone threshold', function (): void {
    // With safe_zone = 0.10, a LONG at position 0.85 is OUTSIDE the penalty
    // band (tighter zone) so multiplier is 1.0. Previously (0.20) it was 0.75.
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '108.5',
        r1: '110', r3: '120', s1: '100', s3: '90',
        safeZone: 0.10,
    ))->toBe(1.0);

    // At position 0.95 with safe_zone=0.10 → (1-0.95)/0.10 = 0.5
    expect(SupportResistanceProximity::computeMultiplier(
        direction: 'LONG',
        markPrice: '109.5',
        r1: '110', r3: '120', s1: '100', s3: '90',
        safeZone: 0.10,
    ))->toBe(0.5);
});
