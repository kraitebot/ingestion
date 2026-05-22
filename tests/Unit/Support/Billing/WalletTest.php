<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\InsufficientFundsException;
use Kraite\Core\Support\Billing\Wallet;

uses(RefreshDatabase::class)->group('billing', 'wallet');

function billingTier(string $canonical = 'starter', float $monthly = 75.0): Subscription
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

function billingUser(float $balance = 0.0, ?int $tierId = null): User
{
    return User::factory()->create([
        'wallet_balance_usdt' => $balance,
        'subscription_id' => $tierId ?? billingTier()->id,
    ]);
}

it('credits the wallet, updates balance and writes a ledger row', function (): void {
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

it('debits the wallet, updates balance and writes a signed-negative ledger row', function (): void {
    $user = billingUser(balance: 200.0);

    $tx = (new Wallet())->debit(
        user: $user,
        amount: 75.0,
        type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        description: 'Monthly debit test',
    );

    expect($user->refresh()->wallet_balance_usdt)->toEqual(125.0);

    expect($tx->amount_usdt)->toEqual(-75.0);
    expect($tx->balance_after)->toEqual(125.0);
    expect($tx->type)->toBe(WalletTransaction::TYPE_DEBIT_SUBSCRIPTION);
});

it('rejects a debit when balance is below the requested amount', function (): void {
    $user = billingUser(balance: 10.0);

    expect(fn () => (new Wallet())->debit(
        user: $user,
        amount: 75.0,
        type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        description: 'Too much',
    ))->toThrow(InsufficientFundsException::class);

    expect($user->refresh()->wallet_balance_usdt)->toEqual(10.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('rejects negative-or-zero amounts on credit', function (): void {
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

it('rejects negative-or-zero amounts on debit', function (): void {
    $user = billingUser(balance: 200);

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

it('runRenewal debits the monthly rate and pushes the anchor +1 month from the current value', function (): void {
    $tier = billingTier('starter', monthly: 75.0);
    $anchor = now()->subDay(); // due renewal
    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 200,
        'subscription_renews_at' => $anchor,
    ]);

    $tx = (new Wallet())->runRenewal($user);

    expect($user->refresh()->wallet_balance_usdt)->toEqual(125.0);

    $expected = $anchor->copy()->addMonth();
    expect($user->subscription_renews_at->toDateString())
        ->toBe($expected->toDateString());

    expect($tx->type)->toBe(WalletTransaction::TYPE_DEBIT_SUBSCRIPTION);
    expect($tx->amount_usdt)->toEqual(-75.0);
    expect($tx->balance_after)->toEqual(125.0);
    expect($tx->meta['rate_at_run'])->toEqual(75.0);
    expect($tx->meta['subscription_canonical'])->toBe('starter');
});

it('runRenewal anchors to now+1 month when the user has no existing renews_at', function (): void {
    $tier = billingTier('unlimited', monthly: 150.0);
    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 200,
        'subscription_renews_at' => null,
    ]);

    (new Wallet())->runRenewal($user);

    $expected = now()->addMonth();
    expect($user->refresh()->subscription_renews_at->toDateString())
        ->toBe($expected->toDateString());
});

it('runRenewal honours an explicit anchor (read-only-unlock case)', function (): void {
    $tier = billingTier('starter', monthly: 75.0);
    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 200,
        'subscription_renews_at' => now()->subDays(5),
    ]);

    $explicit = now()->addMonth()->subDay();

    (new Wallet())->runRenewal($user, $explicit);

    expect($user->refresh()->subscription_renews_at->toDateString())
        ->toBe($explicit->toDateString());
});

it('runRenewal throws InsufficientFundsException and rolls back when wallet is short', function (): void {
    $tier = billingTier('starter', monthly: 75.0);
    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 30,
        'subscription_renews_at' => now()->subDay(),
    ]);

    expect(fn () => (new Wallet())->runRenewal($user))
        ->toThrow(InsufficientFundsException::class);

    expect($user->refresh()->wallet_balance_usdt)->toEqual(30.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('runRenewal rejects users without a subscription tier', function (): void {
    $user = User::factory()->create([
        'subscription_id' => null,
        'wallet_balance_usdt' => 200,
    ]);

    expect(fn () => (new Wallet())->runRenewal($user))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps balance and ledger consistent across a sequence of operations', function (): void {
    $user = billingUser(balance: 0.0);
    $wallet = new Wallet();

    $wallet->credit($user, 100, WalletTransaction::TYPE_CREDIT_TOPUP, 'Topup #1');
    $wallet->credit($user, 50, WalletTransaction::TYPE_CREDIT_PRORATE_REFUND, 'Prorate refund');
    $wallet->debit($user, 75, WalletTransaction::TYPE_DEBIT_SUBSCRIPTION, 'Renewal');
    $wallet->debit($user, 5, WalletTransaction::TYPE_DEBIT_ADMIN, 'Adjustment');

    expect($user->refresh()->wallet_balance_usdt)->toEqual(70.0);

    $rows = WalletTransaction::where('user_id', $user->id)
        ->orderBy('id')
        ->pluck('balance_after')
        ->map(fn ($v) => (float) $v)
        ->all();

    expect($rows)->toBe([100.0, 150.0, 75.0, 70.0]);
});
