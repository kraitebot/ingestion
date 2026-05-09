<?php

declare(strict_types=1);

/**
 * `get_market_order_amount_divider($N)` controls the share of margin × leverage
 * the MARKET entry consumes. The historic formula `2^(N+1)` was tuned for
 * martingale ladders (N≥1) where the geometric LIMIT rungs together commit
 * the remaining `(2^(N+1) − 1) / 2^(N+1)` of the budget.
 *
 * With `total_limit_orders = 0` (simple-trade mode), no ladder ever forms, so
 * the formula's reservation for unfilled rungs becomes wasted capital — the
 * MARKET should consume the full notional. The helper now special-cases N=0
 * to return 1 while preserving the martingale curve for N≥1 untouched.
 */
test('returns 1 when total_limit_orders is 0 — full notional commits to MARKET (simple-trade mode)', function (): void {
    expect(get_market_order_amount_divider(0))->toBe(1);
});

test('preserves the 2^(N+1) martingale curve for N >= 1', function (int $totalLimitOrders, int $expectedDivider): void {
    expect(get_market_order_amount_divider($totalLimitOrders))->toBe($expectedDivider);
})->with([
    'N=1 (1 limit rung)' => [1, 4],
    'N=2 (2 limit rungs)' => [2, 8],
    'N=3 (3 limit rungs)' => [3, 16],
    'N=4 (4 limit rungs, current default)' => [4, 32],
    'N=5 (deeper ladder)' => [5, 64],
]);

test('coerces non-integer numeric input safely (defensive)', function (): void {
    // Older callers may pass int-as-string from JSON arguments — the helper
    // should not require strict int typing for the N=0 branch to fire.
    expect(get_market_order_amount_divider('0'))->toBe(1);
    expect(get_market_order_amount_divider('4'))->toBe(32);
});
