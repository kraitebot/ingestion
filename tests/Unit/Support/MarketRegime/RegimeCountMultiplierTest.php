<?php

declare(strict_types=1);

use Kraite\Core\Support\MarketRegime\RegimeCountMultiplier;

/**
 * Discrete per-band position-count ramp (Phase 3). Scales the
 * per-direction position-count cap down as the BSCS regime worsens, so
 * fewer correlated stop-losses can fire together in a drawdown. Gate
 * only — never force-closes existing positions.
 *
 *   band_cap = floor(account_max_count × for($score))
 */
it('returns 1.0 for a null score (pre-first-compute, fail safe)', function (): void {
    expect(RegimeCountMultiplier::for(null))->toBe(1.0);
});

it('returns 1.0 across the Calm band (0-39)', function (): void {
    expect(RegimeCountMultiplier::for(0))->toBe(1.0)
        ->and(RegimeCountMultiplier::for(39))->toBe(1.0);
});

it('returns 0.75 across the Elevated band (40-59)', function (): void {
    expect(RegimeCountMultiplier::for(40))->toBe(0.75)
        ->and(RegimeCountMultiplier::for(59))->toBe(0.75);
});

it('returns 0.50 across the Fragile band (60-79)', function (): void {
    expect(RegimeCountMultiplier::for(60))->toBe(0.50)
        ->and(RegimeCountMultiplier::for(79))->toBe(0.50);
});

it('returns 0.0 at Critical (80+) — no new opens', function (): void {
    expect(RegimeCountMultiplier::for(80))->toBe(0.0)
        ->and(RegimeCountMultiplier::for(100))->toBe(0.0);
});

it('floors a 6-cap to the boss-spec counts per band', function (): void {
    // The 6+6 account: calm 6, elevated floor(6×0.75)=4, fragile floor(6×0.5)=3, crit 0.
    expect((int) floor(6 * RegimeCountMultiplier::for(10)))->toBe(6)   // calm
        ->and((int) floor(6 * RegimeCountMultiplier::for(50)))->toBe(4) // elevated
        ->and((int) floor(6 * RegimeCountMultiplier::for(70)))->toBe(3) // fragile
        ->and((int) floor(6 * RegimeCountMultiplier::for(85)))->toBe(0); // critical
});
