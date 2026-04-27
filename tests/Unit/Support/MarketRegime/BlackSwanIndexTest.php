<?php

declare(strict_types=1);

use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;

/**
 * Read-side façade over the kraite singleton's BSCS columns. Two
 * consumer types use this:
 *
 *   - Admin dashboard widget — reads score, band, sub-state for display.
 *   - HasTradingGuards::canOpenPositions() — calls shouldBlockOpens() to
 *     decide if the global opens-gate is closed (system cooldown active
 *     OR an operator override forces opens through, depending on state).
 *
 * Resolution rule for `shouldBlockOpens()`:
 *
 *   if  bscs_override_until > now()  →  false  (operator escape hatch)
 *   elif bscs_cooldown_until > now() →  true   (system cooldown active)
 *   else                              →  false (no block)
 *
 * The override beats the cooldown so an operator can manually force
 * opens through during a system-set cooldown window if they decide the
 * regime is mis-reading the market.
 */
function setKraiteBscs(array $attrs): void
{
    Kraite::find(1)->updateSaving(array_merge([
        'bscs_score' => 0,
        'bscs_band' => RegimeBand::Calm->value,
        'bscs_synced_at' => now(),
        'bscs_block_active' => false,
        'bscs_block_threshold' => 80,
        'bscs_freshness_max_seconds' => 6900,
        'bscs_override_until' => null,
        'bscs_cooldown_until' => null,
    ], $attrs));
}

it('reports score, band, and synced_at from the kraite singleton', function (): void {
    setKraiteBscs(['bscs_score' => 60, 'bscs_band' => RegimeBand::Fragile->value]);

    $index = BlackSwanIndex::current();

    expect($index->score())->toBe(60)
        ->and($index->band())->toBe(RegimeBand::Fragile)
        ->and($index->syncedAt())->not->toBeNull();
});

it('shouldBlockOpens returns true while cooldown is in the future', function (): void {
    setKraiteBscs([
        'bscs_score' => 80,
        'bscs_band' => RegimeBand::Critical->value,
        'bscs_cooldown_until' => now()->addHours(20),
    ]);

    expect(BlackSwanIndex::current()->shouldBlockOpens())->toBeTrue();
});

it('shouldBlockOpens returns false once cooldown has expired even if score still high', function (): void {
    // Cooldown timestamp in the past — analyse cron has not yet re-armed.
    // Until the next analyse tick observes the still-high score, opens
    // are technically allowed (fail-open between ticks). Analyse cron
    // closes that gap on next run.
    setKraiteBscs([
        'bscs_score' => 90,
        'bscs_band' => RegimeBand::Critical->value,
        'bscs_cooldown_until' => now()->subHour(),
    ]);

    expect(BlackSwanIndex::current()->shouldBlockOpens())->toBeFalse();
});

it('shouldBlockOpens returns false when no cooldown is set', function (): void {
    setKraiteBscs(['bscs_score' => 20]);

    expect(BlackSwanIndex::current()->shouldBlockOpens())->toBeFalse();
});

it('operator override beats an active system cooldown', function (): void {
    // Both timestamps in the future — override wins. Lets ops force
    // opens through if they've manually assessed the market as safe
    // despite the cooldown being on.
    setKraiteBscs([
        'bscs_score' => 80,
        'bscs_band' => RegimeBand::Critical->value,
        'bscs_cooldown_until' => now()->addHours(12),
        'bscs_override_until' => now()->addHours(2),
    ]);

    $index = BlackSwanIndex::current();

    expect($index->shouldBlockOpens())->toBeFalse()
        ->and($index->isOverrideActive())->toBeTrue()
        ->and($index->isCooldownActive())->toBeTrue();
});

it('expired override does not bypass an active cooldown', function (): void {
    setKraiteBscs([
        'bscs_score' => 80,
        'bscs_band' => RegimeBand::Critical->value,
        'bscs_cooldown_until' => now()->addHours(12),
        'bscs_override_until' => now()->subHour(),
    ]);

    expect(BlackSwanIndex::current()->shouldBlockOpens())->toBeTrue();
});

it('isStale reports true when synced_at is older than freshness_max_seconds', function (): void {
    setKraiteBscs([
        'bscs_synced_at' => now()->subSeconds(7200),  // 2h
        'bscs_freshness_max_seconds' => 6900,         // 115min
    ]);

    expect(BlackSwanIndex::current()->isStale())->toBeTrue();
});

it('isStale reports false when synced_at is within the freshness window', function (): void {
    setKraiteBscs([
        'bscs_synced_at' => now()->subMinutes(30),
        'bscs_freshness_max_seconds' => 6900,
    ]);

    expect(BlackSwanIndex::current()->isStale())->toBeFalse();
});

it('toArray exposes a full payload for dashboards', function (): void {
    $cooldownUntil = now()->addHours(8);

    setKraiteBscs([
        'bscs_score' => 80,
        'bscs_band' => RegimeBand::Critical->value,
        'bscs_synced_at' => now()->subMinutes(15),
        'bscs_cooldown_until' => $cooldownUntil,
    ]);

    $payload = BlackSwanIndex::current()->toArray();

    expect($payload)->toHaveKey('score', 80)
        ->and($payload)->toHaveKey('band', 'critical')
        ->and($payload)->toHaveKey('should_block_opens', true)
        ->and($payload)->toHaveKey('cooldown_active', true)
        ->and($payload)->toHaveKey('override_active', false)
        ->and($payload)->toHaveKey('is_stale', false);
});

it('exposes DirectionalBookRisk via portfolioRisk()', function (): void {
    setKraiteBscs(['bscs_score' => 60, 'bscs_band' => RegimeBand::Fragile->value]);

    $index = BlackSwanIndex::current();

    expect($index->portfolioRisk())
        ->toBeInstanceOf(\Kraite\Core\Support\MarketRegime\DirectionalBookRisk::class);
});

it('toArray includes portfolio_risk block', function (): void {
    setKraiteBscs(['bscs_score' => 60]);

    $payload = BlackSwanIndex::current()->toArray();

    expect($payload)->toHaveKey('portfolio_risk')
        ->and($payload['portfolio_risk'])->toHaveKey('largest_side')
        ->and($payload['portfolio_risk'])->toHaveKey('largest_side_ratio');
});

it('staleness() returns Fresh when synced_at is recent', function (): void {
    setKraiteBscs(['bscs_synced_at' => now()->subMinutes(30), 'bscs_freshness_max_seconds' => 6900]);

    expect(BlackSwanIndex::current()->staleness())
        ->toBe(\Kraite\Core\Enums\BscsStaleness::Fresh);
});

it('staleness() returns StaleSoft between freshness_max and stale_hard cutoff', function (): void {
    // 3 hours old. freshness_max_seconds = 6900s (~115min). stale_hard cutoff = 6h (default).
    setKraiteBscs(['bscs_synced_at' => now()->subHours(3), 'bscs_freshness_max_seconds' => 6900]);

    expect(BlackSwanIndex::current()->staleness())
        ->toBe(\Kraite\Core\Enums\BscsStaleness::StaleSoft);
});

it('staleness() returns StaleHard past the 6h cutoff', function (): void {
    setKraiteBscs(['bscs_synced_at' => now()->subHours(7), 'bscs_freshness_max_seconds' => 6900]);

    expect(BlackSwanIndex::current()->staleness())
        ->toBe(\Kraite\Core\Enums\BscsStaleness::StaleHard);
});

it('staleness() returns StaleHard when synced_at is null (never computed)', function (): void {
    setKraiteBscs(['bscs_synced_at' => null]);

    expect(BlackSwanIndex::current()->staleness())
        ->toBe(\Kraite\Core\Enums\BscsStaleness::StaleHard);
});

it('shouldBlockOpens returns true under StaleSoft when cooldown is active (preserve last posture)', function (): void {
    // Stale-soft means data is older than fresh but younger than 6h.
    // Per spec, last cooldown/scaler state is PRESERVED — gate keeps
    // blocking. Operator gets warned via appLog but the gate doesn't
    // fail open until stale-hard.
    setKraiteBscs([
        'bscs_synced_at' => now()->subHours(3),
        'bscs_cooldown_until' => now()->addHours(20),
    ]);

    expect(BlackSwanIndex::current()->shouldBlockOpens())->toBeTrue();
});

it('shouldBlockOpens fails open (returns false) under StaleHard even if cooldown is active', function (): void {
    // Stale-hard means we can't trust the cooldown state — could be
    // arming on outdated data. Fail open so the bot doesn't lock out
    // on stale signals during a multi-hour outage.
    setKraiteBscs([
        'bscs_synced_at' => now()->subHours(7),
        'bscs_cooldown_until' => now()->addHours(20),
    ]);

    expect(BlackSwanIndex::current()->shouldBlockOpens())->toBeFalse();
});

it('returns a sentinel "no data" instance when the kraite row is missing bscs_synced_at', function (): void {
    // Edge case before the very first compute lands: synced_at is null.
    // Index should still construct (no exceptions) and report no-block /
    // stale-true so the dashboard renders something sensible.
    setKraiteBscs(['bscs_synced_at' => null]);

    $index = BlackSwanIndex::current();

    expect($index->syncedAt())->toBeNull()
        ->and($index->isStale())->toBeTrue()
        ->and($index->shouldBlockOpens())->toBeFalse();
});
