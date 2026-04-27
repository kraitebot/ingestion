<?php

declare(strict_types=1);

use Kraite\Core\Support\MarketRegime\FragileMarginMultiplier;

/**
 * Original-spec linear margin scaler across the Fragile band (BSCS
 * 60-79). Calm/Elevated below 60 → 1.0× (no scaling). Score reaches
 * 80 → block fires (gate-level), scaler is moot. Inside the Fragile
 * band the multiplier ramps linearly from 1.0 → 0.5.
 *
 * Composes multiplicatively with `CrowdingMultiplier` in
 * `PreparePositionDataJob`:
 *
 *   final_margin = base_margin × FragileMarginMultiplier::for($score)
 *                              × CrowdingMultiplier::for($direction, $bookRisk)
 */
it('returns 1.0 when score is below the Fragile lower bound (60)', function (): void {
    expect(FragileMarginMultiplier::for(0))->toBe(1.0)
        ->and(FragileMarginMultiplier::for(40))->toBe(1.0)
        ->and(FragileMarginMultiplier::for(59))->toBe(1.0);
});

it('returns 1.0 at the Fragile lower bound (60) — boundary inclusive, no reduction yet', function (): void {
    expect(FragileMarginMultiplier::for(60))->toBe(1.0);
});

it('returns 0.5 at the Fragile upper bound (79) — full 50% reduction', function (): void {
    expect(FragileMarginMultiplier::for(79))->toBe(0.5);
});

it('interpolates linearly across the Fragile band (60-79)', function (): void {
    // Worked examples from the spec's "Fragile margin reduction" table:
    //   60 →  1.00× (0% reduction)
    //   65 →  ~0.87× (~13% reduction)
    //   70 →  ~0.74× (~26% reduction)
    //   75 →  ~0.61× (~39% reduction)
    //   79 →  0.50× (50% reduction)
    expect(FragileMarginMultiplier::for(65))->toEqualWithDelta(0.868, 0.005)
        ->and(FragileMarginMultiplier::for(70))->toEqualWithDelta(0.737, 0.005)
        ->and(FragileMarginMultiplier::for(75))->toEqualWithDelta(0.605, 0.005);
});

it('returns 1.0 at score 80+ (gate-level block fires; scaler is moot)', function (): void {
    // The block_threshold is 80; opens are halted by the gate, so the
    // scaler value is never observed by callers in this regime. We
    // still return 1.0 for safety so a misconfigured gate doesn't
    // accidentally produce a 0-or-undefined multiplier.
    expect(FragileMarginMultiplier::for(80))->toBe(1.0)
        ->and(FragileMarginMultiplier::for(95))->toBe(1.0)
        ->and(FragileMarginMultiplier::for(100))->toBe(1.0);
});

it('handles null score (pre-first-compute) by returning 1.0 — fail safe to no scaling', function (): void {
    expect(FragileMarginMultiplier::for(null))->toBe(1.0);
});

it('reads bounds from config when overridden', function (): void {
    // Operator can tune the bounds via env without redeploy. Test
    // confirms the multiplier picks up the override.
    config([
        'kraite.market_regime.fragile.lower_bound' => 50,
        'kraite.market_regime.fragile.upper_bound' => 69,
        'kraite.market_regime.fragile.max_reduction_pct' => 50,
    ]);

    expect(FragileMarginMultiplier::for(50))->toBe(1.0)
        ->and(FragileMarginMultiplier::for(69))->toBe(0.5)
        ->and(FragileMarginMultiplier::for(60))->toEqualWithDelta(0.737, 0.005);
});
