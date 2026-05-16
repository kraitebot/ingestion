<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;

/*
 * Account::balanceForTrading() decides which exchange-side balance
 * figure feeds margin sizing. The operator selects whether Kraite trades
 * from total wallet balance or available balance; allow_other_* stays
 * scoped to orphan cleanup policy.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function seedAccountBalanceSnapshot(Account $account, string $totalWallet, string $available): void
{
    ApiSnapshot::storeFor($account, 'account-balance', [
        'total-wallet-balance' => $totalWallet,
        'available-balance' => $available,
    ]);
}

test('returns total-wallet-balance when the trading balance basis is total', function () {
    $account = Account::factory()->create([
        'balance_for_trading_basis' => 'total',
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    seedAccountBalanceSnapshot($account, totalWallet: '1000.00', available: '600.00');

    expect($account->balanceForTrading())->toBe('1000.00');
});

test('returns available-balance when the trading balance basis is available', function () {
    $account = Account::factory()->create([
        'balance_for_trading_basis' => 'available',
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ]);

    seedAccountBalanceSnapshot($account, totalWallet: '1000.00', available: '600.00');

    expect($account->balanceForTrading())->toBe('600.00');
});

test('falls back to account.margin when no balance snapshot exists', function () {
    $account = Account::factory()->create([
        'balance_for_trading_basis' => 'total',
        'margin' => '250.00000000',
    ]);

    expect($account->balanceForTrading())->toBe('250.00000000');
});

test('falls back to account.margin when snapshot lacks the expected key', function () {
    $account = Account::factory()->create([
        'balance_for_trading_basis' => 'available',
        'margin' => '777.00000000',
    ]);

    ApiSnapshot::storeFor($account, 'account-balance', ['some-other-key' => '999']);

    expect($account->balanceForTrading())->toBe('777.00000000');
});
