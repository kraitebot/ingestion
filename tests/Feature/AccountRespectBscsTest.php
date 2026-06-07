<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Trading\Kraite as Engine;

/**
 * Per-account BSCS bypass: accounts.respect_bscs (default true).
 * true  → the account honours the global BlackSwan/BSCS cooldown gate.
 * false → the account opens positions even while BSCS is suspending
 *         opens fleet-wide ("don't wait for BSCS"). Only the BSCS gate is
 *         bypassed — the master kill + allow_opening_positions still apply.
 */
function putBscsIntoBlockingState(): void
{
    Kraite::query()->update([
        'allow_opening_positions' => true,
        'can_trade' => null,
        'bscs_synced_at' => now(),                 // fresh → not stale-hard (which fails open)
        'bscs_freshness_max_seconds' => 6900,
        'bscs_cooldown_until' => now()->addHour(), // cooldown active
        'bscs_override_until' => null,             // no override
    ]);
}

it('blocks opens under BSCS when the account respects it (default behaviour)', function (): void {
    putBscsIntoBlockingState();
    $account = Account::factory()->create(['respect_bscs' => true]);

    expect(Engine::withAccount($account)->canOpenPositions())->toBeFalse();
});

it('lets an account ignore the BSCS gate when respect_bscs is false', function (): void {
    putBscsIntoBlockingState();
    $account = Account::factory()->create(['respect_bscs' => false]);

    expect(Engine::withAccount($account)->canOpenPositions())->toBeTrue();
});

it('respect_bscs=false still honours the master kill and allow_opening_positions', function (): void {
    putBscsIntoBlockingState();
    $account = Account::factory()->create(['respect_bscs' => false]);

    Kraite::query()->update(['can_trade' => false]);
    expect(Engine::withAccount($account)->canOpenPositions())->toBeFalse();

    Kraite::query()->update(['can_trade' => null, 'allow_opening_positions' => false]);
    expect(Engine::withAccount($account)->canOpenPositions())->toBeFalse();
});

it('defaults respect_bscs to true for new accounts', function (): void {
    $account = Account::factory()->create();

    expect($account->respectsBscs())->toBeTrue();
});
