<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Health\AccountActivityFlagSync;

/*
 * AccountActivityFlagSync keeps the allow_other_positions /
 * allow_other_orders protection flags aligned with live evidence of
 * user activity on the exchange account:
 *
 *   - foreign activity present  → both flags ON (protects the user)
 *   - no foreign activity       → both flags OFF (restores total-balance
 *     sizing and Kraite-exclusive cleanup), but ONLY when the evidence
 *     is reliable ($canDisable) — never on an in-flight tick.
 */

uses(RefreshDatabase::class);

test('enables both protection flags when foreign activity appears on a clean account', function (): void {
    $account = Account::factory()->create([
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ]);

    $change = AccountActivityFlagSync::sync($account, hasForeign: true, canDisable: true);

    expect($change)->toBe('enabled')
        ->and($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();
});

test('disables both protection flags when foreign activity is gone and evidence is reliable', function (): void {
    $account = Account::factory()->create([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    $change = AccountActivityFlagSync::sync($account, hasForeign: false, canDisable: true);

    expect($change)->toBe('disabled')
        ->and($account->fresh()->allow_other_positions)->toBeFalse()
        ->and($account->fresh()->allow_other_orders)->toBeFalse();
});

test('never disables on an unreliable tick even with zero foreign evidence', function (): void {
    $account = Account::factory()->create([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    $change = AccountActivityFlagSync::sync($account, hasForeign: false, canDisable: false);

    expect($change)->toBeNull()
        ->and($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();
});

test('still enables on an unreliable tick — protection may always tighten', function (): void {
    $account = Account::factory()->create([
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ]);

    $change = AccountActivityFlagSync::sync($account, hasForeign: true, canDisable: false);

    expect($change)->toBe('enabled')
        ->and($account->fresh()->allow_other_positions)->toBeTrue();
});

test('no-op when flags already match the evidence', function (): void {
    $on = Account::factory()->create(['allow_other_positions' => true, 'allow_other_orders' => true]);
    $off = Account::factory()->create(['allow_other_positions' => false, 'allow_other_orders' => false]);

    expect(AccountActivityFlagSync::sync($on, hasForeign: true, canDisable: true))->toBeNull()
        ->and(AccountActivityFlagSync::sync($off, hasForeign: false, canDisable: true))->toBeNull()
        ->and($on->fresh()->allow_other_positions)->toBeTrue()
        ->and($off->fresh()->allow_other_positions)->toBeFalse();
});

test('repairs a half-set flag pair to match the evidence', function (): void {
    $account = Account::factory()->create([
        'allow_other_positions' => false,
        'allow_other_orders' => true,
    ]);

    $change = AccountActivityFlagSync::sync($account, hasForeign: true, canDisable: true);

    expect($change)->toBe('enabled')
        ->and($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();
});
