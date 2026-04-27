<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Jobs\Models\MarketRegime\AnalyseBscsJob;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;

/**
 * AnalyseBscsJob — system-driven cooldown gate.
 *
 * Reads the latest BSCS score from the kraite singleton and decides:
 *
 *   1. Score ≥ cooldown threshold AND no cooldown currently active
 *      → set bscs_cooldown_until = now() + cooldown_hours, fire the
 *      `market_regime_critical` notification once.
 *
 *   2. Score ≥ threshold AND cooldown active and just expired
 *      → re-arm: another cooldown_hours window. (Operator hasn't seen
 *      the regime resolve, so we keep opens off.)
 *
 *   3. Score < threshold AND cooldown was active but just expired
 *      → release: leave bscs_cooldown_until in the past, fire the
 *      `market_regime_recovered` notification, opens resume.
 *
 *   4. Cooldown still in the future
 *      → no-op (already armed, nothing to do).
 *
 * Distinct from `ComputeMarketRegimeJob`. Compute writes the score
 * snapshot. Analyse acts on it.
 */
function setBscsState(int $score, ?CarbonImmutable $cooldownUntil = null, ?CarbonImmutable $overrideUntil = null): void
{
    Kraite::find(1)->updateSaving([
        'bscs_score' => $score,
        'bscs_band' => RegimeBand::fromScore($score)->value,
        'bscs_synced_at' => now(),
        'bscs_block_active' => false,
        'bscs_block_threshold' => 80,
        'bscs_freshness_max_seconds' => 6900,
        'bscs_cooldown_until' => $cooldownUntil,
        'bscs_override_until' => $overrideUntil,
    ]);
}

it('arms a cooldown when score crosses threshold and no cooldown is active', function (): void {
    setBscsState(score: 80);

    $job = new AnalyseBscsJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('cooldown_armed')
        ->and($kraite->bscs_cooldown_until)->not->toBeNull()
        ->and($kraite->bscs_cooldown_until->isFuture())->toBeTrue()
        ->and(BlackSwanIndex::current()->shouldBlockOpens())->toBeTrue();
});

it('does not re-arm while a cooldown is already active', function (): void {
    $futureCooldown = CarbonImmutable::now()->addHours(20);
    setBscsState(score: 90, cooldownUntil: $futureCooldown);

    $job = new AnalyseBscsJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    // Cooldown timestamp untouched — already armed, nothing to do.
    expect($result['action'])->toBe('cooldown_already_active')
        ->and($kraite->bscs_cooldown_until?->toIso8601String())
            ->toBe($futureCooldown->toIso8601String());
});

it('re-arms a fresh cooldown when previous expired and score still high', function (): void {
    setBscsState(
        score: 85,
        cooldownUntil: CarbonImmutable::now()->subMinutes(10),
    );

    $job = new AnalyseBscsJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('cooldown_rearmed')
        ->and($kraite->bscs_cooldown_until?->isFuture())->toBeTrue();
});

it('releases the cooldown when score drops below threshold after expiry', function (): void {
    $expired = CarbonImmutable::now()->subHour();
    setBscsState(score: 40, cooldownUntil: $expired);

    $job = new AnalyseBscsJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('cooldown_released')
        ->and(BlackSwanIndex::current()->shouldBlockOpens())->toBeFalse()
        ->and($kraite->bscs_cooldown_until?->isFuture())->toBeFalse();
});

it('no-ops when score is below threshold and no cooldown was active', function (): void {
    setBscsState(score: 20);

    $job = new AnalyseBscsJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('noop_below_threshold')
        ->and($kraite->bscs_cooldown_until)->toBeNull();
});

it('does not arm cooldown when an operator override is currently active', function (): void {
    setBscsState(
        score: 100,
        overrideUntil: CarbonImmutable::now()->addHours(2),
    );

    $job = new AnalyseBscsJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    // Operator says "I've manually assessed this is fine" — analyse
    // respects the escape hatch and does NOT arm a cooldown that
    // would just be ignored anyway. Saves a phantom notification.
    expect($result['action'])->toBe('noop_override_active')
        ->and($kraite->bscs_cooldown_until)->toBeNull();
});

it('uses the configured cooldown_hours window when arming', function (): void {
    config(['kraite.market_regime.cooldown.hours' => 12]);
    setBscsState(score: 80);

    $beforeArm = CarbonImmutable::now();

    $job = new AnalyseBscsJob;
    $job->compute();

    $kraite = Kraite::find(1)->refresh();

    $cooldownUntil = $kraite->bscs_cooldown_until;
    $diffHours = abs($cooldownUntil->diffInHours($beforeArm));

    // Allow 1 second of clock drift between $beforeArm and the actual
    // cooldown timestamp computation inside the job.
    expect($diffHours)->toBeBetween(11.99, 12.01);
});
