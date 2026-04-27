<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\MarketRegime\CrowdingMultiplier;
use Kraite\Core\Support\MarketRegime\DirectionalBookRisk;

/**
 * Directional crowding multiplier — only DOWNSCALES the crowded side.
 * Empty side stays at 1.0× (NEUTRAL) until backtest evidence justifies
 * upscaling (locked policy in spec).
 *
 * Rule:
 *
 *   if direction matches largest_side AND largest_side_ratio >= 0.7:
 *       interpolate 1.0 → 0.5 across ratio 0.7 → 1.0
 *   else:
 *       1.0
 */
function makeOpenForCrowding(string $direction, string $margin, int $leverage): void
{
    Position::factory()->create([
        'account_id' => Account::factory()->create()->id,
        'direction' => $direction,
        'status' => 'active',
        'margin' => $margin,
        'leverage' => $leverage,
    ]);
}

it('returns 1.0 when book is balanced (no crowding)', function (): void {
    makeOpenForCrowding('LONG', '100', 10);
    makeOpenForCrowding('SHORT', '100', 10);

    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toBe(1.0)
        ->and(CrowdingMultiplier::for('SHORT', $book))->toBe(1.0);
});

it('returns 1.0 when book is below the 0.7 crowding threshold', function (): void {
    // 60/40 split: largest_side_ratio = 0.6. Below the 0.7 trigger.
    makeOpenForCrowding('LONG', '600', 1);
    makeOpenForCrowding('SHORT', '400', 1);

    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toBe(1.0)
        ->and(CrowdingMultiplier::for('SHORT', $book))->toBe(1.0);
});

it('returns 1.0 at the 0.7 boundary — inclusive lower edge, no reduction yet', function (): void {
    // 70/30 → ratio = 0.7
    makeOpenForCrowding('LONG', '700', 1);
    makeOpenForCrowding('SHORT', '300', 1);

    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toBe(1.0);
});

it('downscales the crowded side linearly: ratio 0.7 → 1.0× , ratio 1.0 → 0.5×', function (): void {
    // Pure 100% LONG → ratio 1.0 → multiplier 0.5
    makeOpenForCrowding('LONG', '1000', 1);

    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toBe(0.5);
});

it('downscales linearly at intermediate ratios — 0.85 → 0.75×', function (): void {
    // 85/15 split → ratio 0.85 → multiplier should be midway
    // between 1.0 (at 0.7) and 0.5 (at 1.0): about 0.75.
    makeOpenForCrowding('LONG', '850', 1);
    makeOpenForCrowding('SHORT', '150', 1);

    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toEqualWithDelta(0.75, 0.005);
});

it('does NOT downscale the empty side regardless of ratio (locked: no upside)', function (): void {
    // 95% LONG: LONG side gets heavy downscale, SHORT side stays at 1.0×.
    // The "open against the crowd is safer" theory is not enabled
    // until backtest evidence validates it across the 6 events.
    makeOpenForCrowding('LONG', '950', 1);
    makeOpenForCrowding('SHORT', '50', 1);

    $book = DirectionalBookRisk::current();

    // LONG side: ratio 0.95, downscaled
    expect(CrowdingMultiplier::for('LONG', $book))->toBeLessThan(1.0);

    // SHORT side: NEUTRAL despite the heavy crowding on the OTHER side
    expect(CrowdingMultiplier::for('SHORT', $book))->toBe(1.0);
});

it('returns 1.0 when no positions exist (zero exposure baseline)', function (): void {
    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toBe(1.0)
        ->and(CrowdingMultiplier::for('SHORT', $book))->toBe(1.0);
});

it('returns 1.0 when book is BALANCED equal-margin even at extreme position counts', function (): void {
    // 5 LONGs of 100 vs 5 SHORTs of 100 — counts are equal, margin
    // is equal, ratio is 0.5 → no crowding signal.
    for ($i = 0; $i < 5; $i++) {
        makeOpenForCrowding('LONG', '100', 1);
        makeOpenForCrowding('SHORT', '100', 1);
    }

    $book = DirectionalBookRisk::current();

    expect(CrowdingMultiplier::for('LONG', $book))->toBe(1.0)
        ->and(CrowdingMultiplier::for('SHORT', $book))->toBe(1.0);
});
