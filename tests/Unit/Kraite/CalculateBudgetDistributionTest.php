<?php

declare(strict_types=1);

use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Kraite;

/**
 * Tests for calculateBudgetDistribution() - Budget-based Martingale margin distribution.
 *
 * Core constraints tested:
 * 1. Total margin MUST equal budget exactly (no exceeding, no waste)
 * 2. Multiplier ratios are preserved: limit[i+1] / limit[i] = multiplier[i+1]
 * 3. Last limit gets ~50% of budget (martingale property with equal multipliers)
 * 4. Market order is smallest, each limit increases by multiplier ratio
 */

// ============================================================================
// Equal Multipliers [2,2,2,2] - Classic martingale doubling
// ============================================================================

test('distributes budget correctly with equal multipliers [2,2,2,2] and 4 limit orders', function (): void {
    $budget = '1500';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Verify structure
    expect($result)->toHaveKeys(['market', 'limits', 'total', 'weights']);
    expect($result['limits'])->toHaveCount(4);

    // CRITICAL: Total margin MUST equal budget exactly
    $total = (float) $result['total'];
    expect($total)->toEqualWithDelta(1500.0, 0.01);

    // Verify market is smallest
    $market = (float) $result['market'];
    expect($market)->toBeGreaterThan(0);
    expect($market)->toBeLessThan((float) $result['limits'][0]);

    // Verify multiplier ratios are preserved
    // L1 / Market = 2
    $ratio0 = Math::div($result['limits'][0], $result['market'], 8);
    expect((float) $ratio0)->toEqualWithDelta(2.0, 0.001);

    // L2 / L1 = 2
    $ratio1 = Math::div($result['limits'][1], $result['limits'][0], 8);
    expect((float) $ratio1)->toEqualWithDelta(2.0, 0.001);

    // L3 / L2 = 2
    $ratio2 = Math::div($result['limits'][2], $result['limits'][1], 8);
    expect((float) $ratio2)->toEqualWithDelta(2.0, 0.001);

    // L4 / L3 = 2
    $ratio3 = Math::div($result['limits'][3], $result['limits'][2], 8);
    expect((float) $ratio3)->toEqualWithDelta(2.0, 0.001);

    // Verify martingale property: last limit ~50% of budget
    $lastLimitPct = (float) $result['limits'][3] / 1500.0 * 100;
    expect($lastLimitPct)->toBeGreaterThan(48)->toBeLessThan(53);
});

test('distributes budget correctly with equal multipliers [2,2,2,2] and 2 limit orders', function (): void {
    $budget = '1000';
    $multipliers = [2, 2, 2, 2];
    $N = 2;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(1000.0, 0.01);
    expect($result['limits'])->toHaveCount(2);

    // Ratios preserved
    $ratio0 = Math::div($result['limits'][0], $result['market'], 8);
    expect((float) $ratio0)->toEqualWithDelta(2.0, 0.001);

    $ratio1 = Math::div($result['limits'][1], $result['limits'][0], 8);
    expect((float) $ratio1)->toEqualWithDelta(2.0, 0.001);

    // With N=2 and multipliers [2,2]: S = 1 + 1/2 + 1/4 = 1.75
    // Last limit = 1000/1.75 = 571.43 (~57.14%)
    $lastLimitPct = (float) $result['limits'][1] / 1000.0 * 100;
    expect($lastLimitPct)->toBeGreaterThan(55)->toBeLessThan(60);
});

test('distributes budget correctly with equal multipliers [3,3,3,3] - higher ratio', function (): void {
    $budget = '3000';
    $multipliers = [3, 3, 3, 3];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(3000.0, 0.01);

    // All ratios should be 3
    $ratio0 = Math::div($result['limits'][0], $result['market'], 8);
    expect((float) $ratio0)->toEqualWithDelta(3.0, 0.001);

    $ratio1 = Math::div($result['limits'][1], $result['limits'][0], 8);
    expect((float) $ratio1)->toEqualWithDelta(3.0, 0.001);

    // Higher multiplier = higher concentration at the end
    // Last limit should be > 60% with 3x multipliers
    $lastLimitPct = (float) $result['limits'][3] / 3000.0 * 100;
    expect($lastLimitPct)->toBeGreaterThan(60);
});

// ============================================================================
// Variable Multipliers [2, 1.5, 2.5, 2] - Mixed ratios
// ============================================================================

test('distributes budget correctly with variable multipliers [2, 1.5, 2.5, 2]', function (): void {
    $budget = '1500';
    $multipliers = [2, 1.5, 2.5, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(1500.0, 0.01);

    // Verify each ratio matches the multiplier
    // L1 / Market = 2
    $ratio0 = Math::div($result['limits'][0], $result['market'], 8);
    expect((float) $ratio0)->toEqualWithDelta(2.0, 0.01);

    // L2 / L1 = 1.5
    $ratio1 = Math::div($result['limits'][1], $result['limits'][0], 8);
    expect((float) $ratio1)->toEqualWithDelta(1.5, 0.01);

    // L3 / L2 = 2.5
    $ratio2 = Math::div($result['limits'][2], $result['limits'][1], 8);
    expect((float) $ratio2)->toEqualWithDelta(2.5, 0.01);

    // L4 / L3 = 2
    $ratio3 = Math::div($result['limits'][3], $result['limits'][2], 8);
    expect((float) $ratio3)->toEqualWithDelta(2.0, 0.01);
});

test('distributes budget correctly with aggressive multipliers [4, 3, 2, 1.5]', function (): void {
    $budget = '5000';
    $multipliers = [4, 3, 2, 1.5];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(5000.0, 0.01);

    // Verify ratios
    expect((float) Math::div($result['limits'][0], $result['market'], 8))->toEqualWithDelta(4.0, 0.01);
    expect((float) Math::div($result['limits'][1], $result['limits'][0], 8))->toEqualWithDelta(3.0, 0.01);
    expect((float) Math::div($result['limits'][2], $result['limits'][1], 8))->toEqualWithDelta(2.0, 0.01);
    expect((float) Math::div($result['limits'][3], $result['limits'][2], 8))->toEqualWithDelta(1.5, 0.01);
});

test('distributes budget correctly with conservative multipliers [1.2, 1.3, 1.4, 1.5]', function (): void {
    $budget = '2000';
    $multipliers = [1.2, 1.3, 1.4, 1.5];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(2000.0, 0.01);

    // Verify ratios
    expect((float) Math::div($result['limits'][0], $result['market'], 8))->toEqualWithDelta(1.2, 0.01);
    expect((float) Math::div($result['limits'][1], $result['limits'][0], 8))->toEqualWithDelta(1.3, 0.01);
    expect((float) Math::div($result['limits'][2], $result['limits'][1], 8))->toEqualWithDelta(1.4, 0.01);
    expect((float) Math::div($result['limits'][3], $result['limits'][2], 8))->toEqualWithDelta(1.5, 0.01);

    // With low multipliers, distribution is more even
    // Last limit should be < 40% (compared to ~50% with 2x multipliers)
    $lastLimitPct = (float) $result['limits'][3] / 2000.0 * 100;
    expect($lastLimitPct)->toBeLessThan(40);
});

// ============================================================================
// Single Limit Order (N=1)
// ============================================================================

test('distributes budget correctly with single limit order (N=1)', function (): void {
    $budget = '1000';
    $multipliers = [2];
    $N = 1;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(1000.0, 0.01);
    expect($result['limits'])->toHaveCount(1);

    // With N=1 and multiplier=2: S = 1 + 1/2 = 1.5
    // Last limit = 1000/1.5 = 666.67 (66.67%)
    // Market = 666.67/2 = 333.33 (33.33%)
    $market = (float) $result['market'];
    $limit = (float) $result['limits'][0];

    expect($market)->toEqualWithDelta(333.33, 1.0);
    expect($limit)->toEqualWithDelta(666.67, 1.0);

    // Ratio should be 2
    $ratio = $limit / $market;
    expect($ratio)->toEqualWithDelta(2.0, 0.01);
});

test('distributes budget correctly with single limit order and high multiplier', function (): void {
    $budget = '1000';
    $multipliers = [5];
    $N = 1;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(1000.0, 0.01);

    // With N=1 and multiplier=5: S = 1 + 1/5 = 1.2
    // Last limit = 1000/1.2 = 833.33 (83.33%)
    // Market = 833.33/5 = 166.67 (16.67%)
    $limit = (float) $result['limits'][0];
    $limitPct = $limit / 1000.0 * 100;
    expect($limitPct)->toEqualWithDelta(83.33, 1.0);
});

// ============================================================================
// Zero Limit Orders (N=0) - All budget to market
// ============================================================================

test('allocates all budget to market when N=0', function (): void {
    $budget = '1000';
    $multipliers = [2, 2, 2, 2];
    $N = 0;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // All budget goes to market
    expect((float) $result['market'])->toEqualWithDelta(1000.0, 0.01);
    expect($result['limits'])->toBeEmpty();
    expect((float) $result['total'])->toEqualWithDelta(1000.0, 0.01);

    // Weight should be 100%
    expect((float) $result['weights'][0])->toEqualWithDelta(1.0, 0.001);
});

// ============================================================================
// Edge Cases - Budget Values
// ============================================================================

test('handles very small budget correctly', function (): void {
    $budget = '10';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(10.0, 0.001);

    // Ratios still preserved
    $ratio0 = Math::div($result['limits'][0], $result['market'], 8);
    expect((float) $ratio0)->toEqualWithDelta(2.0, 0.01);
});

test('handles very large budget correctly', function (): void {
    $budget = '1000000';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(1000000.0, 1.0);

    // Ratios still preserved
    $ratio0 = Math::div($result['limits'][0], $result['market'], 8);
    expect((float) $ratio0)->toEqualWithDelta(2.0, 0.001);
});

test('handles decimal budget correctly', function (): void {
    $budget = '1234.56789';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total must equal budget
    expect((float) $result['total'])->toEqualWithDelta(1234.56789, 0.001);
});

test('handles string numeric budget', function (): void {
    $budget = '1500.00';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    expect((float) $result['total'])->toEqualWithDelta(1500.0, 0.01);
});

test('handles integer budget', function (): void {
    $budget = 1500;
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    expect((float) $result['total'])->toEqualWithDelta(1500.0, 0.01);
});

test('handles float budget', function (): void {
    $budget = 1500.50;
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    expect((float) $result['total'])->toEqualWithDelta(1500.50, 0.01);
});

// ============================================================================
// Edge Cases - Multipliers with more entries than N
// ============================================================================

test('uses only needed multipliers when array is longer than N', function (): void {
    $budget = '1000';
    $multipliers = [2, 3, 4, 5, 6, 7, 8]; // More than needed
    $N = 3;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    expect((float) $result['total'])->toEqualWithDelta(1000.0, 0.01);
    expect($result['limits'])->toHaveCount(3);

    // Should use first 3 multipliers: 2, 3, 4
    expect((float) Math::div($result['limits'][0], $result['market'], 8))->toEqualWithDelta(2.0, 0.01);
    expect((float) Math::div($result['limits'][1], $result['limits'][0], 8))->toEqualWithDelta(3.0, 0.01);
    expect((float) Math::div($result['limits'][2], $result['limits'][1], 8))->toEqualWithDelta(4.0, 0.01);
});

test('repeats last multiplier when array is shorter than N', function (): void {
    $budget = '1000';
    $multipliers = [2, 3]; // Shorter than N
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    expect((float) $result['total'])->toEqualWithDelta(1000.0, 0.01);
    expect($result['limits'])->toHaveCount(4);

    // Should use: 2, 3, 3, 3 (last repeats)
    expect((float) Math::div($result['limits'][0], $result['market'], 8))->toEqualWithDelta(2.0, 0.01);
    expect((float) Math::div($result['limits'][1], $result['limits'][0], 8))->toEqualWithDelta(3.0, 0.01);
    expect((float) Math::div($result['limits'][2], $result['limits'][1], 8))->toEqualWithDelta(3.0, 0.01);
    expect((float) Math::div($result['limits'][3], $result['limits'][2], 8))->toEqualWithDelta(3.0, 0.01);
});

// ============================================================================
// Weights Calculation
// ============================================================================

test('weights sum to 1.0', function (): void {
    $budget = '1500';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    $weightSum = '0';
    foreach ($result['weights'] as $weight) {
        $weightSum = Math::add($weightSum, $weight, 16);
    }

    expect((float) $weightSum)->toEqualWithDelta(1.0, 0.0001);
});

test('weights reflect correct proportions', function (): void {
    $budget = '1000';
    $multipliers = [2];
    $N = 1;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // With N=1 and m=2: market ~33.33%, limit ~66.67%
    expect((float) $result['weights'][0])->toEqualWithDelta(0.3333, 0.01);
    expect((float) $result['weights'][1])->toEqualWithDelta(0.6667, 0.01);
});

// ============================================================================
// Error Cases - Invalid Inputs
// ============================================================================

test('throws exception for zero budget', function (): void {
    Kraite::calculateBudgetDistribution('0', [2, 2, 2, 2], 4);
})->throws(InvalidArgumentException::class, 'Budget must be numeric and > 0');

test('throws exception for negative budget', function (): void {
    Kraite::calculateBudgetDistribution('-100', [2, 2, 2, 2], 4);
})->throws(InvalidArgumentException::class, 'Budget must be numeric and > 0');

test('throws exception for non-numeric budget', function (): void {
    Kraite::calculateBudgetDistribution('invalid', [2, 2, 2, 2], 4);
})->throws(InvalidArgumentException::class, 'Budget must be numeric and > 0');

test('throws exception for empty multipliers array', function (): void {
    Kraite::calculateBudgetDistribution('1000', [], 4);
})->throws(InvalidArgumentException::class, 'Multipliers array cannot be empty');

test('throws exception for negative total limit orders', function (): void {
    Kraite::calculateBudgetDistribution('1000', [2, 2, 2, 2], -1);
})->throws(InvalidArgumentException::class, 'Total limit orders must be >= 0');

test('throws exception for zero multiplier', function (): void {
    Kraite::calculateBudgetDistribution('1000', [2, 0, 2, 2], 4);
})->throws(InvalidArgumentException::class, 'Multiplier at index 1 must be a positive number');

test('throws exception for negative multiplier', function (): void {
    Kraite::calculateBudgetDistribution('1000', [2, -1.5, 2, 2], 4);
})->throws(InvalidArgumentException::class, 'Multiplier at index 1 must be a positive number');

test('throws exception for non-numeric multiplier', function (): void {
    Kraite::calculateBudgetDistribution('1000', [2, 'invalid', 2, 2], 4);
})->throws(InvalidArgumentException::class, 'Multiplier at index 1 must be a positive number');

// ============================================================================
// Real-World Scenario Tests
// ============================================================================

test('simulates 5% max position on 30000 balance with 4 limit orders', function (): void {
    // balance = 30000, max_position_pct = 5%
    // budget = 30000 * 5 / 100 = 1500
    $budget = '1500';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // CRITICAL: Total margin must NOT exceed 5% of balance (1500 USDT)
    expect((float) $result['total'])->toBeLessThanOrEqual(1500.0);
    expect((float) $result['total'])->toEqualWithDelta(1500.0, 0.01);

    // Market should be ~3.22% of budget (48.39 USDT)
    $market = (float) $result['market'];
    expect($market)->toBeGreaterThan(45)->toBeLessThan(52);

    // Last limit should be ~51.61% of budget (774.19 USDT)
    $lastLimit = (float) $result['limits'][3];
    expect($lastLimit)->toBeGreaterThan(750)->toBeLessThan(800);
});

test('simulates 10% max position on 50000 balance with 6 limit orders', function (): void {
    // balance = 50000, max_position_pct = 10%
    // budget = 50000 * 10 / 100 = 5000
    $budget = '5000';
    $multipliers = [2, 2, 2, 2, 2, 2];
    $N = 6;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total margin must NOT exceed 10% of balance (5000 USDT)
    expect((float) $result['total'])->toBeLessThanOrEqual(5000.0);
    expect((float) $result['total'])->toEqualWithDelta(5000.0, 0.01);

    expect($result['limits'])->toHaveCount(6);

    // Each step should double
    for ($i = 0; $i < 6; $i++) {
        $prev = $i === 0 ? $result['market'] : $result['limits'][$i - 1];
        $ratio = Math::div($result['limits'][$i], $prev, 8);
        expect((float) $ratio)->toEqualWithDelta(2.0, 0.01);
    }
});

test('simulates 3% max position on 100000 balance with variable multipliers', function (): void {
    // balance = 100000, max_position_pct = 3%
    // budget = 100000 * 3 / 100 = 3000
    $budget = '3000';
    $multipliers = [1.5, 2.0, 2.5, 3.0];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // Total margin must equal budget (within floating point tolerance)
    expect((float) $result['total'])->toEqualWithDelta(3000.0, 0.01);

    // Verify variable multipliers are preserved
    expect((float) Math::div($result['limits'][0], $result['market'], 8))->toEqualWithDelta(1.5, 0.01);
    expect((float) Math::div($result['limits'][1], $result['limits'][0], 8))->toEqualWithDelta(2.0, 0.01);
    expect((float) Math::div($result['limits'][2], $result['limits'][1], 8))->toEqualWithDelta(2.5, 0.01);
    expect((float) Math::div($result['limits'][3], $result['limits'][2], 8))->toEqualWithDelta(3.0, 0.01);
});

// ============================================================================
// Mathematical Verification
// ============================================================================

test('verifies S formula calculation for [2,2,2,2] with N=4', function (): void {
    // S = 1 + 1/2 + 1/(2*2) + 1/(2*2*2) + 1/(2*2*2*2)
    // S = 1 + 0.5 + 0.25 + 0.125 + 0.0625 = 1.9375
    $budget = '1000';
    $multipliers = [2, 2, 2, 2];
    $N = 4;

    $result = Kraite::calculateBudgetDistribution($budget, $multipliers, $N);

    // last_limit = 1000 / 1.9375 = 516.129...
    $lastLimit = (float) $result['limits'][3];
    expect($lastLimit)->toEqualWithDelta(516.129, 0.1);

    // market = 516.129 / 2^4 = 32.258...
    $market = (float) $result['market'];
    expect($market)->toEqualWithDelta(32.258, 0.1);
});

test('verifies all positions sum to budget exactly regardless of multipliers', function (): void {
    // Test multiple configurations
    $configs = [
        ['budget' => '1000', 'multipliers' => [2, 2, 2, 2], 'N' => 4],
        ['budget' => '5000', 'multipliers' => [1.5, 2, 2.5, 3], 'N' => 4],
        ['budget' => '777.77', 'multipliers' => [3, 3, 3], 'N' => 3],
        ['budget' => '10000', 'multipliers' => [1.1, 1.2, 1.3, 1.4, 1.5], 'N' => 5],
    ];

    foreach ($configs as $config) {
        $result = Kraite::calculateBudgetDistribution(
            $config['budget'],
            $config['multipliers'],
            $config['N']
        );

        // Manually sum all positions
        $sum = $result['market'];
        foreach ($result['limits'] as $limit) {
            $sum = Math::add($sum, $limit, 16);
        }

        expect((float) $sum)->toEqualWithDelta((float) $config['budget'], 0.0001);
    }
});
