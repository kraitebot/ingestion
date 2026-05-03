<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;

/*
 * Account::balanceForTrading() decides which exchange-side balance
 * figure feeds margin sizing.
 *
 *   both allow_other_* = false  →  total-wallet-balance (current
 *                                  behaviour: full utilisation when
 *                                  Kraite owns the account exclusively)
 *
 *   at least one true            →  available-balance (free margin
 *                                  remaining after locked-in orders +
 *                                  initial margin of open positions —
 *                                  the conservative figure that
 *                                  keeps us safe when the user is
 *                                  also placing trades)
 *
 * The two figures live in the most-recent `account-balance`
 * `ApiSnapshot` row stamped by `StoreAccountBalanceJob`. When no
 * snapshot exists yet (cold start), the helper falls back to the
 * account's `margin` column.
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function seedAccountBalanceSnapshot(Account $account, string $totalWallet, string $available): void
{
    ApiSnapshot::storeFor($account, 'account-balance', [
        'total-wallet-balance' => $totalWallet,
        'available-balance' => $available,
    ]);
}

test('returns total-wallet-balance when both allow_other_* flags are false', function () {
    $account = Account::factory()->create([
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ]);

    seedAccountBalanceSnapshot($account, totalWallet: '1000.00', available: '600.00');

    expect($account->balanceForTrading())->toBe('1000.00');
});

test('returns available-balance when allow_other_orders is true', function () {
    $account = Account::factory()->create([
        'allow_other_positions' => false,
        'allow_other_orders' => true,
    ]);

    seedAccountBalanceSnapshot($account, totalWallet: '1000.00', available: '600.00');

    expect($account->balanceForTrading())->toBe('600.00');
});

test('returns available-balance when allow_other_positions is true', function () {
    $account = Account::factory()->create([
        'allow_other_positions' => true,
        'allow_other_orders' => false,
    ]);

    seedAccountBalanceSnapshot($account, totalWallet: '1000.00', available: '600.00');

    expect($account->balanceForTrading())->toBe('600.00');
});

test('returns available-balance when both allow_other_* flags are true', function () {
    $account = Account::factory()->create([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    seedAccountBalanceSnapshot($account, totalWallet: '1000.00', available: '600.00');

    expect($account->balanceForTrading())->toBe('600.00');
});

test('falls back to account.margin when no balance snapshot exists', function () {
    $account = Account::factory()->create([
        'allow_other_positions' => false,
        'allow_other_orders' => false,
        'margin' => '250.00000000',
    ]);

    expect($account->balanceForTrading())->toBe('250.00000000');
});

test('falls back to account.margin when snapshot lacks the expected key', function () {
    $account = Account::factory()->create([
        'allow_other_positions' => true,
        'allow_other_orders' => false,
        'margin' => '777.00000000',
    ]);

    ApiSnapshot::storeFor($account, 'account-balance', ['some-other-key' => '999']);

    expect($account->balanceForTrading())->toBe('777.00000000');
});
