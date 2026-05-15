<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Support\Billing\BillingManager;
use Kraite\Core\Support\Billing\SubscriptionState;

/**
 * Spec for the 3-gate readiness facade Bruno asked for during the
 * private-beta onboarding elicitation:
 *
 *   $user->billing()->subscription()->isActive()  // gate 3
 *   $account->isReadyToTrade()                    // wraps gates 1+2+3
 *
 * Matrix:
 *   gate 1 → account.is_active && account.can_trade
 *   gate 2 → user.can_trade
 *   gate 3 → subscription is currently valid (trial OR coverage,
 *            not paused, anchor in future)
 *
 * Returns true iff ALL THREE pass. Any single false → false.
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

    return [$user, $account];
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

it('account isReadyToTrade returns false when user.can_trade is false (gate 2)', function (): void {
    [, $account] = freshUserAndAccount(userOverrides: ['can_trade' => false]);

    expect($account->isReadyToTrade())->toBeFalse();
});

it('account isReadyToTrade returns false when subscription is paused (gate 3)', function (): void {
    [, $account] = freshUserAndAccount(userOverrides: ['subscription_paused_at' => now()]);

    expect($account->isReadyToTrade())->toBeFalse();
});
