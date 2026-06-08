<?php

declare(strict_types=1);

use Kraite\Core\Support\MarketRegime\RegimeLeverageMultiplier;

/**
 * Discrete per-band leverage ramp (Phase 3). Scales the determined
 * leverage down as the BSCS regime worsens, pushing the liquidation
 * cliff further from entry. Calm = full, Critical = moot (opens blocked).
 *
 *   final_leverage = max(1, floor(base_leverage × for($score)))
 */
it('returns 1.0 for a null score (pre-first-compute, fail safe)', function (): void {
    expect(RegimeLeverageMultiplier::for(null))->toBe(1.0);
});

it('returns 1.0 across the Calm band (0-39)', function (): void {
    expect(RegimeLeverageMultiplier::for(0))->toBe(1.0)
        ->and(RegimeLeverageMultiplier::for(39))->toBe(1.0);
});

it('returns 0.66 across the Elevated band (40-59)', function (): void {
    expect(RegimeLeverageMultiplier::for(40))->toBe(0.66)
        ->and(RegimeLeverageMultiplier::for(59))->toBe(0.66);
});

it('returns 0.50 across the Fragile band (60-79)', function (): void {
    expect(RegimeLeverageMultiplier::for(60))->toBe(0.50)
        ->and(RegimeLeverageMultiplier::for(79))->toBe(0.50);
});

it('returns 1.0 at Critical (80+) — opens are blocked, ratio is moot', function (): void {
    expect(RegimeLeverageMultiplier::for(80))->toBe(1.0)
        ->and(RegimeLeverageMultiplier::for(100))->toBe(1.0);
});
