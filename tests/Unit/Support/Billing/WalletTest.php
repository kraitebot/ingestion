<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\InsufficientFundsException;
use Kraite\Core\Support\Billing\Wallet;

uses(RefreshDatabase::class)->group('billing', 'wallet');

function billingTier(string $canonical = 'starter', float $rate = 2.5): Subscription
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

function billingUser(float $balance = 0.0, ?int $tierId = null): User
{
    return User::factory()->create([
        'wallet_balance_usdt' => $balance,
        'subscription_id' => $tierId ?? billingTier()->id,
    ]);
}

it('credits the wallet, updates balance and writes a ledger row', function () {
    $user = billingUser(balance: 10.0);

    $tx = (new Wallet())->credit(
        user: $user,
        amount: 50.0,
        type: WalletTransaction::TYPE_CREDIT_TOPUP,
        description: 'Test credit',
        meta: ['source' => 'test'],
    );

    expect($user->refresh()->wallet_balance_usdt)
        ->toEqual(60.0);

    expect($tx->amount_usdt)->toEqual(50.0);
    expect($tx->balance_after)->toEqual(60.0);
    expect($tx->type)->toBe(WalletTransaction::TYPE_CREDIT_TOPUP);
    expect($tx->description)->toBe('Test credit');
    expect($tx->meta)->toBe(['source' => 'test']);
    expect($tx->user_id)->toBe($user->id);
});

it('debits the wallet, updates balance and writes a signed-negative ledger row', function () {
    $user = billingUser(balance: 50.0);

    $tx = (new Wallet())->debit(
        user: $user,
        amount: 12.5,
        type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        description: 'Daily debit test',
    );

    expect($user->refresh()->wallet_balance_usdt)->toEqual(37.5);

    expect($tx->amount_usdt)->toEqual(-12.5);
    expect($tx->balance_after)->toEqual(37.5);
    expect($tx->type)->toBe(WalletTransaction::TYPE_DEBIT_SUBSCRIPTION);
});

it('rejects a debit when balance is below the requested amount', function () {
    $user = billingUser(balance: 1.0);

    expect(fn () => (new Wallet())->debit(
        user: $user,
        amount: 2.5,
        type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        description: 'Too much',
    ))->toThrow(InsufficientFundsException::class);

    expect($user->refresh()->wallet_balance_usdt)->toEqual(1.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('rejects negative-or-zero amounts on credit', function () {
    $user = billingUser();

    expect(fn () => (new Wallet())->credit(
        user: $user,
        amount: 0.0,
        type: WalletTransaction::TYPE_CREDIT_ADMIN,
        description: 'zero',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new Wallet())->credit(
        user: $user,
        amount: -1.0,
        type: WalletTransaction::TYPE_CREDIT_ADMIN,
        description: 'neg',
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects negative-or-zero amounts on debit', function () {
    $user = billingUser(balance: 100);

    expect(fn () => (new Wallet())->debit(
        user: $user,
        amount: 0.0,
        type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        description: 'zero',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new Wallet())->debit(
        user: $user,
        amount: -5.0,
        type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        description: 'neg',
    ))->toThrow(InvalidArgumentException::class);
});

it('exposes a bonus ladder matching the spec (50/100/500)', function () {
    expect(Wallet::bonusPercentFor(0))->toBe(0);
    expect(Wallet::bonusPercentFor(49.99))->toBe(0);
    expect(Wallet::bonusPercentFor(50))->toBe(5);
    expect(Wallet::bonusPercentFor(75))->toBe(5);
    expect(Wallet::bonusPercentFor(99.99))->toBe(5);
    expect(Wallet::bonusPercentFor(100))->toBe(10);
    expect(Wallet::bonusPercentFor(250))->toBe(10);
    expect(Wallet::bonusPercentFor(499.99))->toBe(10);
    expect(Wallet::bonusPercentFor(500))->toBe(15);
    expect(Wallet::bonusPercentFor(10_000))->toBe(15);
});

it('keeps balance and ledger consistent across a sequence of operations', function () {
    $user = billingUser(balance: 0.0);
    $wallet = new Wallet();

    $wallet->credit($user, 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'Topup #1');
    $wallet->credit($user, 10, WalletTransaction::TYPE_CREDIT_TOPUP_BONUS, 'Bonus 10%');
    $wallet->debit($user, 2.5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Day 1');
    $wallet->debit($user, 2.5, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Day 2');

    expect($user->refresh()->wallet_balance_usdt)->toEqual(105.0);

    $rows = WalletTransaction::where('user_id', $user->id)
        ->orderBy('id')
        ->pluck('balance_after')
        ->map(fn ($v) => (float) $v)
        ->all();

    expect($rows)->toBe([100.0, 110.0, 107.5, 105.0]);
});
