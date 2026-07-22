<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Support\Billing\BillingManager;
use Kraite\Core\Support\Billing\SubscriptionState;

/**
 * Spec for the per-account readiness facade used by position-opening
 * workflows:
 *
 *   $user->billing()->subscription()->isActive()  // gate 3
 *   $account->isReadyToTrade()                    // wraps all gates
 *
 * Matrix:
 *   gate 1 → account.is_active && account.can_trade
 *   gate 2 → user.is_active && user.can_trade
 *   gate 3 → subscription is currently valid (trial OR coverage,
 *            not paused, anchor in future)
 *   gate 4 → designated active account on one-account tiers
 *
 * Returns true iff every gate passes. Any single false → false.
 */
function freshUserAndAccount(array $userOverrides = [], array $accountOverrides = []): array
{
    $subscription = Subscription::firstOrCreate(
        ['canonical' => 'basic'],
        ['name' => 'Basic', 'monthly_rate_usdt' => '75.0000', 'trial_days' => 7]
    );

    $user = User::factory()->create(array_merge([
        'subscription_id' => $subscription->id,
        'can_trade' => true,
        'wallet_balance_usdt' => '100.0000',
        'subscription_renews_at' => now()->addDays(30),
        'trial_started_at' => null,
        'subscription_paused_at' => null,
    ], $userOverrides));

    $account = Account::factory()->create(array_merge([
        'user_id' => $user->id,
        'is_active' => true,
        'can_trade' => true,
    ], $accountOverrides));

    if ($subscription->max_accounts === 1 && ! array_key_exists('active_account_id', $userOverrides)) {
        $user->update(['active_account_id' => $account->id]);
    }

    return [$user->refresh(), $account];
}

it('exposes $user->billing() returning a BillingManager', function (): void {
    [$user] = freshUserAndAccount();

    expect($user->billing())->toBeInstanceOf(BillingManager::class);
});

it('exposes $user->billing()->subscription() returning a SubscriptionState', function (): void {
    [$user] = freshUserAndAccount();

    expect($user->billing()->subscription())->toBeInstanceOf(SubscriptionState::class);
});

it('subscription is active when wallet covers next renewal and anchor is in future', function (): void {
    [$user] = freshUserAndAccount([
        'wallet_balance_usdt' => '200.0000',
        'subscription_renews_at' => now()->addDays(15),
    ]);

    expect($user->billing()->subscription()->isActive())->toBeTrue();
});

it('subscription is INACTIVE when paused', function (): void {
    [$user] = freshUserAndAccount(['subscription_paused_at' => now()]);

    expect($user->billing()->subscription()->isActive())->toBeFalse();
    expect($user->billing()->subscription()->isPaused())->toBeTrue();
});

it('subscription is INACTIVE when renewal anchor is in the past', function (): void {
    [$user] = freshUserAndAccount(['subscription_renews_at' => now()->subDay()]);

    expect($user->billing()->subscription()->isActive())->toBeFalse();
});

it('subscription is ACTIVE during trial regardless of wallet balance', function (): void {
    [$user] = freshUserAndAccount([
        'trial_started_at' => now()->subDays(1),
        'trial_days_override' => 7,
        'wallet_balance_usdt' => '0.0000',
        'subscription_renews_at' => null,
    ]);

    expect($user->billing()->subscription()->isInTrial())->toBeTrue();
    expect($user->billing()->subscription()->isActive())->toBeTrue();
});

it('account isReadyToTrade returns true when all three gates pass', function (): void {
    [, $account] = freshUserAndAccount();

    expect($account->isReadyToTrade())->toBeTrue();
});

it('account isReadyToTrade returns false when account.can_trade is false (gate 1)', function (): void {
    [, $account] = freshUserAndAccount(accountOverrides: ['can_trade' => false]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade returns false when account.is_active is false (gate 1)', function (): void {
    [, $account] = freshUserAndAccount(accountOverrides: ['is_active' => false]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade returns false when its API system is inactive', function (): void {
    [, $account] = freshUserAndAccount();

    expect($account->isReadyToTrade())->toBeTrue();

    $account->apiSystem->update(['is_active' => false]);

    expect($account->fresh()->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade returns false when user.can_trade is false (gate 2)', function (): void {
    [, $account] = freshUserAndAccount(userOverrides: ['can_trade' => false]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade returns false when the user is inactive', function (): void {
    [, $account] = freshUserAndAccount(userOverrides: ['is_active' => false]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade blocks a capped plan without a designated account', function (): void {
    [, $account] = freshUserAndAccount(userOverrides: ['active_account_id' => null]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade blocks a non-designated account on a capped plan', function (): void {
    [$user, $designated] = freshUserAndAccount();
    $otherAccount = Account::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'can_trade' => true,
    ]);

    expect($designated->isReadyToTrade())->toBeTrue();
    expect($otherAccount->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade returns false when subscription is paused (gate 3)', function (): void {
    [, $account] = freshUserAndAccount(userOverrides: ['subscription_paused_at' => now()]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade allows a unified BitGet account after the v3 order surface ships', function (): void {
    $bitget = Kraite\Core\Models\ApiSystem::firstOrCreate(
        ['canonical' => 'bitget'],
        ['name' => 'BitGet', 'is_active' => true, 'is_exchange' => true, 'recvwindow_margin' => 1000]
    );

    [, $account] = freshUserAndAccount(accountOverrides: [
        'api_system_id' => $bitget->id,
        'bitget_account_mode' => 'unified',
    ]);

    expect($account->isReadyToTrade())->toBeTrue();
});

it('account isReadyToTrade allows a classic BitGet account through the mode gate', function (): void {
    $bitget = Kraite\Core\Models\ApiSystem::firstOrCreate(
        ['canonical' => 'bitget'],
        ['name' => 'BitGet', 'is_active' => true, 'is_exchange' => true, 'recvwindow_margin' => 1000]
    );

    [, $account] = freshUserAndAccount(accountOverrides: [
        'api_system_id' => $bitget->id,
        'bitget_account_mode' => 'classic',
    ]);

    expect($account->isReadyToTrade())->toBeTrue();
});

it('account isReadyToTrade ignores a stray unified marker on a non-BitGet account', function (): void {
    [, $account] = freshUserAndAccount(accountOverrides: [
        'bitget_account_mode' => 'unified',
    ]);

    expect($account->apiSystem->canonical)->not->toBe('bitget');
    expect($account->isReadyToTrade())->toBeTrue();
});
