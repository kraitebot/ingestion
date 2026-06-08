<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Models\Position;

/**
 * AssignBestTokensToPositionSlotsJob caps the per-direction position
 * count by the BSCS regime ramp (Phase 3): band_cap = floor(max × ratio),
 * ratios Calm 100% / Elevated 75% / Fragile 50% / Critical 0%. Gate only —
 * availableSlots clamps at 0, never force-closes. Each opened slot also
 * snapshots the regime band+direction and raw score.
 */
function makeCountAccount(int $maxLong = 6, int $maxShort = 6): Account
{
    return Account::factory()->create([
        'total_positions_long' => $maxLong,
        'total_positions_short' => $maxShort,
    ]);
}

function setRegime(int $score): void
{
    KraiteModel::find(1)->updateSaving([
        'allow_opening_positions' => true,
        'can_trade' => true,
        'bscs_cooldown_until' => null,
        'bscs_synced_at' => now(),
        'bscs_score' => $score,
    ]);
}

it('opens the full 6+6 count in the Calm band', function (): void {
    setRegime(10);
    $result = (new AssignBestTokensToPositionSlotsJob(makeCountAccount(6, 6)->id))->createPositionSlots();

    expect($result['available_slots']['longs'])->toBe(6)
        ->and($result['available_slots']['shorts'])->toBe(6)
        ->and($result['total_created'])->toBe(12);
});

it('caps to 4+4 in the Elevated band (floor 6×0.75)', function (): void {
    setRegime(50);
    $result = (new AssignBestTokensToPositionSlotsJob(makeCountAccount(6, 6)->id))->createPositionSlots();

    expect($result['available_slots']['longs'])->toBe(4)
        ->and($result['available_slots']['shorts'])->toBe(4)
        ->and($result['total_created'])->toBe(8);
});

it('caps to 3+3 in the Fragile band (floor 6×0.5)', function (): void {
    setRegime(70);
    $result = (new AssignBestTokensToPositionSlotsJob(makeCountAccount(6, 6)->id))->createPositionSlots();

    expect($result['available_slots']['longs'])->toBe(3)
        ->and($result['available_slots']['shorts'])->toBe(3)
        ->and($result['total_created'])->toBe(6);
});

it('opens nothing in the Critical band (ratio 0)', function (): void {
    setRegime(85);
    $result = (new AssignBestTokensToPositionSlotsJob(makeCountAccount(6, 6)->id))->createPositionSlots();

    expect($result['available_slots']['longs'])->toBe(0)
        ->and($result['available_slots']['shorts'])->toBe(0)
        ->and($result['total_created'])->toBe(0);
});

it('stamps the BSCS band and score on each opened position', function (): void {
    setRegime(50); // Elevated → floor(2×0.75)=1 each direction
    $account = makeCountAccount(2, 2);

    (new AssignBestTokensToPositionSlotsJob($account->id))->createPositionSlots();

    $long = Position::where('account_id', $account->id)->where('direction', 'LONG')->first();
    $short = Position::where('account_id', $account->id)->where('direction', 'SHORT')->first();

    expect($long->bscs_band)->toBe('elevated-long')
        ->and($long->bscs_score)->toBe(50)
        ->and($short->bscs_band)->toBe('elevated-short')
        ->and($short->bscs_score)->toBe(50);
});

it('freezes new opens when already over the tightened cap, never force-closing', function (): void {
    $account = makeCountAccount(6, 6);

    // Five LONGs already open before the regime tightens.
    Position::factory()->count(5)->create([
        'account_id' => $account->id,
        'direction' => 'LONG',
        'status' => 'active',
    ]);

    setRegime(70); // Fragile → cap floor(6×0.5)=3, but 5 are already open

    $result = (new AssignBestTokensToPositionSlotsJob($account->id))->createPositionSlots();

    // available = max(0, 3 - 5) = 0 → nothing new opens (freeze)
    expect($result['available_slots']['longs'])->toBe(0);

    // The five existing positions are untouched — gate only, no force-close.
    expect(Position::where('account_id', $account->id)->where('direction', 'LONG')->where('status', 'active')->count())->toBe(5);
});
