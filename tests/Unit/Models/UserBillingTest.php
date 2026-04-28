<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;

uses(RefreshDatabase::class)->group('billing', 'user');

function tierFor(string $canonical, float $rate, int $trialDays = 7): Subscription
{
    return Subscription::updateOrCreate(
        ['canonical' => $canonical],
        [
            'name' => ucfirst($canonical),
            'daily_rate_usdt' => $rate,
            'trial_days' => $trialDays,
            'max_accounts' => $canonical === 'starter' ? 1 : null,
            'max_balance' => $canonical === 'starter' ? 10000 : null,
            'is_active' => true,
        ],
    );
}

it('says trial is not active when trial_started_at is null', function () {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 2.5)->id,
        'trial_started_at' => null,
    ]);

    expect($user->isTrialActive())->toBeFalse();
    expect($user->isTrialExpired())->toBeFalse();
});

it('says trial is active during the trial window', function () {
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(3),
    ]);

    expect($user->isTrialActive())->toBeTrue();
    expect($user->isTrialExpired())->toBeFalse();
});

it('says trial is expired after trial_days have elapsed', function () {
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(8),
    ]);

    expect($user->isTrialActive())->toBeFalse();
    expect($user->isTrialExpired())->toBeTrue();
});

it('returns the correct runway in whole days at the active rate', function () {
    $tier = tierFor('starter', 2.5);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 50,
    ]);

    expect($user->walletRunwayDays())->toBe(20);
});

it('returns null runway when no daily rate is set', function () {
    $tier = tierFor('starter', 0.0);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 50,
    ]);

    expect($user->walletRunwayDays())->toBeNull();
});

it('flags closing-mode when wallet cannot cover one daily debit and trial is inactive', function () {
    $tier = tierFor('starter', 2.5);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 1.0,
        'trial_started_at' => null,
    ]);

    expect($user->isInClosingMode())->toBeTrue();
});

it('does not flag closing-mode when balance covers at least one daily debit', function () {
    $tier = tierFor('starter', 2.5);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 2.5,
        'trial_started_at' => null,
    ]);

    expect($user->isInClosingMode())->toBeFalse();
});

it('does not flag closing-mode while the trial is active even with empty wallet', function () {
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 0,
        'trial_started_at' => now()->subDay(),
    ]);

    expect($user->isInClosingMode())->toBeFalse();
});

it('flags closing-mode after the trial expires if the wallet is empty', function () {
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 0,
        'trial_started_at' => now()->subDays(8),
    ]);

    expect($user->isInClosingMode())->toBeTrue();
});

it('honours trial_days_override over the tier default', function () {
    // Tier default is 7 days. User override extends to 30 days.
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(10),
        'trial_days_override' => 30,
    ]);

    expect($user->effectiveTrialDays())->toBe(30);
    expect($user->isTrialActive())->toBeTrue();
    expect($user->isTrialExpired())->toBeFalse();
});

it('falls back to the tier trial_days when override is null', function () {
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(3),
        'trial_days_override' => null,
    ]);

    expect($user->effectiveTrialDays())->toBe(7);
    expect($user->isTrialActive())->toBeTrue();
});

it('treats trial_days_override of zero as no trial granted', function () {
    $tier = tierFor('starter', 2.5, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now(),
        'trial_days_override' => 0,
    ]);

    expect($user->effectiveTrialDays())->toBe(0);
    expect($user->isTrialActive())->toBeFalse();
});
