<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\Wallet;

uses(RefreshDatabase::class)->group('billing', 'ledger');

/**
 * Every credit, debit, bonus, and admin override on a user's wallet
 * MUST land as one append-only row in `wallet_transactions`. The row
 * carries the type, signed amount, post-write balance snapshot,
 * description, and structured meta. This contract is what makes
 * customer-dispute resolution viable — the ledger is read top-to-bottom
 * to reconstruct any balance.
 */
function ledgerTier(string $canonical = 'starter', float $rate = 2.5): Subscription
{
    return Subscription::updateOrCreate(
        ['canonical' => $canonical],
        [
            'name' => ucfirst($canonical),
            'daily_rate_usdt' => $rate,
            'trial_days' => 7,
            'max_accounts' => $canonical === 'starter' ? 1 : null,
            'max_balance' => $canonical === 'starter' ? 10000 : null,
            'is_active' => true,
        ],
    );
}

function ledgerUser(): User
{
    return User::factory()->create([
        'subscription_id' => ledgerTier()->id,
        'wallet_balance_usdt' => 0,
    ]);
}

it('writes a positive ledger row on a top-up credit, with type and meta', function () {
    $user = ledgerUser();

    (new Wallet())->credit(
        user: $user,
        amount: 100,
        type: WalletTransaction::TYPE_CREDIT_TOPUP,
        description: 'NOWPayments top-up #abc123',
        meta: ['payment_id' => 'abc123', 'gateway' => 'nowpayments'],
    );

    $row = WalletTransaction::where('user_id', $user->id)->sole();

    expect($row->type)->toBe(WalletTransaction::TYPE_CREDIT_TOPUP);
    expect((float) $row->amount_usdt)->toEqual(100.0);
    expect($row->isCredit())->toBeTrue();
    expect($row->isDebit())->toBeFalse();
    expect((float) $row->balance_after)->toEqual(100.0);
    expect($row->description)->toBe('NOWPayments top-up #abc123');
    expect($row->meta)->toEqual(['payment_id' => 'abc123', 'gateway' => 'nowpayments']);
});

it('writes a separate ledger row for the bonus credit on top of the top-up', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit(
        user: $user,
        amount: 100,
        type: WalletTransaction::TYPE_CREDIT_TOPUP,
        description: 'Top-up 100 USDT',
        meta: ['gateway' => 'nowpayments'],
    );

    $bonusPct = Wallet::bonusPercentFor(100);
    $bonusAmount = 100 * $bonusPct / 100;

    $wallet->credit(
        user: $user,
        amount: $bonusAmount,
        type: WalletTransaction::TYPE_CREDIT_TOPUP_BONUS,
        description: "Bonus {$bonusPct}% on 100 USDT top-up",
        meta: ['bonus_pct' => $bonusPct, 'on_amount' => 100],
    );

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(110.0);

    $rows = WalletTransaction::where('user_id', $user->id)->orderBy('id')->get();
    expect($rows->count())->toBe(2);

    expect($rows[0]->type)->toBe(WalletTransaction::TYPE_CREDIT_TOPUP);
    expect($rows[1]->type)->toBe(WalletTransaction::TYPE_CREDIT_TOPUP_BONUS);
    expect((float) $rows[1]->amount_usdt)->toEqual(10.0);
    expect((float) $rows[1]->balance_after)->toEqual(110.0);
    expect($rows[1]->meta['bonus_pct'])->toBe(10);
});

it('writes a negative-amount row on a subscription debit', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit($user, 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'Seed');
    $wallet->debit($user, 2.5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Daily Starter');

    $debitRow = WalletTransaction::where('user_id', $user->id)
        ->where('type', WalletTransaction::TYPE_DEBIT_SUBSCRIPTION)
        ->sole();

    expect((float) $debitRow->amount_usdt)->toEqual(-2.5);
    expect((float) $debitRow->balance_after)->toEqual(97.5);
    expect($debitRow->isDebit())->toBeTrue();
    expect($debitRow->isCredit())->toBeFalse();
});

it('records admin overrides with their own type and operator identity', function () {
    $user = ledgerUser();

    (new Wallet())->credit(
        user: $user,
        amount: 75,
        type: WalletTransaction::TYPE_CREDIT_ADMIN,
        description: 'Pre-launch test credit',
        meta: ['admin_user_id' => 999, 'admin_email' => 'bruno@kraite.com'],
    );

    $row = WalletTransaction::where('user_id', $user->id)->sole();

    expect($row->type)->toBe(WalletTransaction::TYPE_CREDIT_ADMIN);
    expect($row->meta['admin_user_id'])->toBe(999);
    expect($row->meta['admin_email'])->toBe('bruno@kraite.com');
});

it('records admin debits separately from system debits', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit($user, 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'seed');
    $wallet->debit($user, 10, WalletTransaction::TYPE_DEBIT_ADMIN, 'Reverse mistaken credit', [
        'admin_user_id' => 999,
    ]);

    $adminDebit = WalletTransaction::where('user_id', $user->id)
        ->where('type', WalletTransaction::TYPE_DEBIT_ADMIN)
        ->sole();

    expect((float) $adminDebit->amount_usdt)->toEqual(-10.0);
    expect($adminDebit->meta['admin_user_id'])->toBe(999);
});

it('preserves the cumulative balance_after sequence reconstructable from the ledger', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $ops = [
        ['credit', 50, WalletTransaction::TYPE_CREDIT_TOPUP, 'Topup #1'],
        ['credit', 2.5, WalletTransaction::TYPE_CREDIT_TOPUP_BONUS, 'Bonus 5%'],
        ['debit', 2.5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Day 1'],
        ['debit', 2.5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Day 2'],
        ['credit', 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'Topup #2'],
        ['credit', 10, WalletTransaction::TYPE_CREDIT_TOPUP_BONUS, 'Bonus 10%'],
        ['debit', 5, WalletTransaction::TYPE_DEBIT_ADMIN, 'Manual reverse', ['admin_user_id' => 1]],
    ];

    foreach ($ops as $op) {
        [$kind, $amount, $type, $desc] = $op;
        $meta = $op[4] ?? [];

        if ($kind === 'credit') {
            $wallet->credit($user, (float) $amount, $type, $desc, $meta);
        } else {
            $wallet->debit($user, (float) $amount, $type, $desc, $meta);
        }
    }

    $balances = WalletTransaction::where('user_id', $user->id)
        ->orderBy('id')
        ->pluck('balance_after')
        ->map(fn ($v) => (float) $v)
        ->all();

    // 50, 52.5, 50, 47.5, 147.5, 157.5, 152.5
    expect($balances)->toBe([50.0, 52.5, 50.0, 47.5, 147.5, 157.5, 152.5]);
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(152.5);
});

it('does not write a ledger row when an InsufficientFundsException is thrown', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit($user, 1, WalletTransaction::TYPE_CREDIT_TOPUP, 'small');

    try {
        $wallet->debit($user, 5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Day 1');
    } catch (\Throwable) {
        // expected
    }

    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(1);
    expect(
        WalletTransaction::where('user_id', $user->id)
            ->where('type', WalletTransaction::TYPE_DEBIT_SUBSCRIPTION)
            ->count()
    )->toBe(0);
});

it('exposes a chronological per-user history via the user relation', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit($user, 50, WalletTransaction::TYPE_CREDIT_TOPUP, '#1');
    $wallet->debit($user, 5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Day 1');
    $wallet->credit($user, 10, WalletTransaction::TYPE_CREDIT_TOPUP_BONUS, 'bonus');

    $history = $user->refresh()->walletTransactions()->orderBy('id')->get();

    expect($history->count())->toBe(3);
    expect($history->pluck('type')->all())->toBe([
        WalletTransaction::TYPE_CREDIT_TOPUP,
        WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        WalletTransaction::TYPE_CREDIT_TOPUP_BONUS,
    ]);
});
