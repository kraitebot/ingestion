<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\Wallet;

uses(RefreshDatabase::class)->group('billing', 'ledger');

/**
 * Every credit, debit, prorate refund, renewal, and admin override on a
 * user's wallet MUST land as one append-only row in `wallet_transactions`.
 * The row carries the type, signed amount, post-write balance snapshot,
 * description, and structured meta. This contract is what makes
 * customer-dispute resolution viable — the ledger is read top-to-bottom
 * to reconstruct any balance.
 */
function ledgerTier(string $canonical = 'starter', float $monthly = 75.0): Subscription
{
    return Subscription::updateOrCreate(
        ['canonical' => $canonical],
        [
            'name' => ucfirst($canonical),
            'monthly_rate_usdt' => $monthly,
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

it('writes a separate ledger row for a prorate refund credit', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit(
        user: $user,
        amount: 150,
        type: WalletTransaction::TYPE_CREDIT_TOPUP,
        description: 'Top-up 150 USDT',
        meta: ['gateway' => 'nowpayments'],
    );

    $wallet->credit(
        user: $user,
        amount: 30,
        type: WalletTransaction::TYPE_CREDIT_PRORATE_REFUND,
        description: 'Prorate refund · Unlimited · 6 unused days',
        meta: ['days_remaining' => 6, 'monthly_rate_usdt' => 150.0],
    );

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(180.0);

    $rows = WalletTransaction::where('user_id', $user->id)->orderBy('id')->get();
    expect($rows->count())->toBe(2);

    expect($rows[0]->type)->toBe(WalletTransaction::TYPE_CREDIT_TOPUP);
    expect($rows[1]->type)->toBe(WalletTransaction::TYPE_CREDIT_PRORATE_REFUND);
    expect((float) $rows[1]->amount_usdt)->toEqual(30.0);
    expect((float) $rows[1]->balance_after)->toEqual(180.0);
    expect($rows[1]->meta['days_remaining'])->toBe(6);
});

it('writes a negative-amount row on a subscription debit', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit($user, 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'Seed');
    $wallet->debit($user, 75, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Monthly Starter');

    $debitRow = WalletTransaction::where('user_id', $user->id)
        ->where('type', WalletTransaction::TYPE_DEBIT_SUBSCRIPTION)
        ->sole();

    expect((float) $debitRow->amount_usdt)->toEqual(-75.0);
    expect((float) $debitRow->balance_after)->toEqual(25.0);
    expect($debitRow->isDebit())->toBeTrue();
    expect($debitRow->isCredit())->toBeFalse();
});

it('runRenewal writes a single TYPE_DEBIT_SUBSCRIPTION row with renews_at meta', function () {
    $tier = ledgerTier('starter', monthly: 75.0);
    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 200,
        'subscription_renews_at' => now()->subDay(),
    ]);

    (new Wallet())->runRenewal($user);

    $row = WalletTransaction::where('user_id', $user->id)->sole();

    expect($row->type)->toBe(WalletTransaction::TYPE_DEBIT_SUBSCRIPTION);
    expect((float) $row->amount_usdt)->toEqual(-75.0);
    expect((float) $row->balance_after)->toEqual(125.0);
    expect($row->meta['rate_at_run'])->toEqual(75.0);
    expect($row->meta['subscription_canonical'])->toBe('starter');
    expect($row->meta)->toHaveKey('renews_at_after');
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
        ['credit', 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'Topup #1'],
        ['debit', 75, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Renewal #1'],
        ['credit', 50, WalletTransaction::TYPE_CREDIT_PRORATE_REFUND, 'Prorate refund'],
        ['credit', 200, WalletTransaction::TYPE_CREDIT_TOPUP, 'Topup #2'],
        ['debit', 150, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Renewal #2'],
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

    // 100, 25, 75, 275, 125, 120
    expect($balances)->toBe([100.0, 25.0, 75.0, 275.0, 125.0, 120.0]);
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(120.0);
});

it('does not write a ledger row when an InsufficientFundsException is thrown', function () {
    $user = ledgerUser();
    $wallet = new Wallet();

    $wallet->credit($user, 1, WalletTransaction::TYPE_CREDIT_TOPUP, 'small');

    try {
        $wallet->debit($user, 75, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Too much');
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

    $wallet->credit($user, 100, WalletTransaction::TYPE_CREDIT_TOPUP, '#1');
    $wallet->debit($user, 75, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Renewal');
    $wallet->credit($user, 25, WalletTransaction::TYPE_CREDIT_PRORATE_REFUND, 'Prorate');

    $history = $user->refresh()->walletTransactions()->orderBy('id')->get();

    expect($history->count())->toBe(3);
    expect($history->pluck('type')->all())->toBe([
        WalletTransaction::TYPE_CREDIT_TOPUP,
        WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        WalletTransaction::TYPE_CREDIT_PRORATE_REFUND,
    ]);
});
