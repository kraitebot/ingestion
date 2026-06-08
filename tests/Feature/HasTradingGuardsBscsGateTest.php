<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Trading\Kraite;

/**
 * The BSCS cooldown is a global, ABSOLUTE gate that flows through
 * `HasTradingGuards::canOpenPositions()`. When BlackSwanIndex says
 * shouldBlockOpens=true (a Critical-armed cooldown in the future), every
 * account is blocked. As of Phase 3 there is no per-account opt-out
 * (`respect_bscs` removed) and no operator override (`bscs_override_until`
 * removed) — Critical is un-overridable. canOpenLongs / canOpenShorts /
 * canOpenNewPositions all cascade through canOpenPositions.
 *
 * Wiring contract:
 *   1. allow_opening_positions=false  → false
 *   2. cooldown_until in the future   → false (absolute, every account)
 *   3. cooldown_until in the past     → true
 */
function makeEngineForGate(): Kraite
{
    return Kraite::withAccount(Account::factory()->create());
}

function setKraiteForGateTest(bool $allowOpens, ?CarbonImmutable $cooldown = null): KraiteModel
{
    $kraite = KraiteModel::find(1);
    $kraite->updateSaving([
        'allow_opening_positions' => $allowOpens,
        'bscs_cooldown_until' => $cooldown,
        // Fresh synced_at so the 3-tier staleness model returns Fresh and
        // shouldBlockOpens() doesn't bypass the cooldown via the StaleHard
        // fail-open clause. These cases exercise cooldown semantics, not
        // staleness — keep that orthogonal axis at its happy-path value.
        'bscs_synced_at' => now(),
        'bscs_freshness_max_seconds' => 6900,
    ]);

    return $kraite;
}

it('allows opens when no BSCS cooldown is set', function (): void {
    setKraiteForGateTest(allowOpens: true);

    expect(makeEngineForGate()->canOpenPositions())->toBeTrue();
});

it('blocks opens when BSCS cooldown is in the future', function (): void {
    setKraiteForGateTest(allowOpens: true, cooldown: CarbonImmutable::now()->addHours(20));

    expect(makeEngineForGate()->canOpenPositions())->toBeFalse();
});

it('still respects allow_opening_positions=false even without a cooldown', function (): void {
    setKraiteForGateTest(allowOpens: false);

    expect(makeEngineForGate()->canOpenPositions())->toBeFalse();
});

it('blocks every account during an active cooldown — no per-account opt-out, no override', function (): void {
    setKraiteForGateTest(allowOpens: true, cooldown: CarbonImmutable::now()->addHours(20));

    $a = Kraite::withAccount(Account::factory()->create());
    $b = Kraite::withAccount(Account::factory()->create());

    expect($a->canOpenPositions())->toBeFalse()
        ->and($b->canOpenPositions())->toBeFalse();
});

it('opens resume once the BSCS cooldown timestamp is in the past', function (): void {
    setKraiteForGateTest(allowOpens: true, cooldown: CarbonImmutable::now()->subHour());

    expect(makeEngineForGate()->canOpenPositions())->toBeTrue();
});
