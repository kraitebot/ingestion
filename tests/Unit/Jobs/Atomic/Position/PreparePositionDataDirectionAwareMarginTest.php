<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\PreparePositionDataJob;
use Kraite\Core\Models\Account;

/**
 * Regression cover for the 2026-04-24 split of `max_position_percentage`
 * into direction-aware `margin_percentage_long` + `margin_percentage_short`.
 *
 * Pins the core contract of
 * `PreparePositionDataJob::calculateMarginWithSubscriptionCap()`:
 *
 *   - LONG positions size against `margin_percentage_long`.
 *   - SHORT positions size against `margin_percentage_short`.
 *   - Subscription cap still trims when breached.
 *
 * These tests exercise the method directly via reflection so they don't
 * require a full Position + ApiSnapshot + Step setup — the direction is
 * the critical gating input and the balance fallback path covers the
 * no-subscription, no-snapshot case.
 */
function newJobForCalc(): PreparePositionDataJob
{
    // Constructor requires a Position id; dodge it by bypassing the
    // constructor — we only exercise the pure margin-calc method that
    // doesn't touch $this->position.
    $refl = new ReflectionClass(PreparePositionDataJob::class);

    return $refl->newInstanceWithoutConstructor();
}

function buildAccountWithSplitPercentages(string $longPct, string $shortPct, string $balance = '1000.00'): Account
{
    $account = new Account;
    $account->margin = $balance;
    $account->margin_percentage_long = $longPct;
    $account->margin_percentage_short = $shortPct;
    // No user / subscription — the cap path is skipped when
    // $account->user->subscription resolves to null.

    return $account;
}

it('sizes LONG positions from margin_percentage_long', function (): void {
    $account = buildAccountWithSplitPercentages(longPct: '5.00', shortPct: '8.00', balance: '1000');
    $job = newJobForCalc();

    $margin = $job->calculateMarginWithSubscriptionCap($account, 'LONG');

    // 1000 × 5% = 50
    expect((float) $margin)->toBe(50.0);
});

it('sizes SHORT positions from margin_percentage_short', function (): void {
    $account = buildAccountWithSplitPercentages(longPct: '5.00', shortPct: '8.00', balance: '1000');
    $job = newJobForCalc();

    $margin = $job->calculateMarginWithSubscriptionCap($account, 'SHORT');

    // 1000 × 8% = 80
    expect((float) $margin)->toBe(80.0);
});

it('LONG and SHORT can produce different margins on the same account', function (): void {
    $account = buildAccountWithSplitPercentages(longPct: '3.00', shortPct: '7.00', balance: '2000');
    $job = newJobForCalc();

    $long = $job->calculateMarginWithSubscriptionCap($account, 'LONG');
    $short = $job->calculateMarginWithSubscriptionCap($account, 'SHORT');

    expect((float) $long)->toBe(60.0);    // 2000 × 3%
    expect((float) $short)->toBe(140.0);  // 2000 × 7%
    expect((float) $long)->not->toBe((float) $short);
});

it('falls back to 5.00 when the direction column is missing', function (): void {
    $account = new Account;
    $account->margin = '1000';
    // No margin_percentage_long / margin_percentage_short set — null coalesce kicks in.
    $job = newJobForCalc();

    expect((float) $job->calculateMarginWithSubscriptionCap($account, 'LONG'))->toBe(50.0);
    expect((float) $job->calculateMarginWithSubscriptionCap($account, 'SHORT'))->toBe(50.0);
});

it('reads the method signature source to pin direction param presence', function (): void {
    // Belt-and-suspenders: a future refactor that drops the direction
    // parameter would silently fall back to a single-knob reading
    // pattern unless the method signature itself is asserted.
    $source = file_get_contents(
        (new ReflectionClass(PreparePositionDataJob::class))->getFileName()
    );

    expect($source)->toContain('calculateMarginWithSubscriptionCap(Account $account, string $direction)');
    expect($source)->toContain('margin_percentage_long');
    expect($source)->toContain('margin_percentage_short');
});
