<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;

uses(RefreshDatabase::class)->group('billing', 'user');

function tierFor(string $canonical, float $monthly, int $trialDays = 7): Subscription
{
    return Subscription::updateOrCreate(
        ['canonical' => $canonical],
        [
            'name' => ucfirst($canonical),
            'monthly_rate_usdt' => $monthly,
            'trial_days' => $trialDays,
            'max_accounts' => $canonical === 'starter' ? 1 : null,
            'max_balance' => $canonical === 'starter' ? 10000 : null,
            'is_active' => true,
        ],
    );
}

it('says trial is not active when trial_started_at is null', function (): void {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'trial_started_at' => null,
    ]);

    expect($user->isTrialActive())->toBeFalse();
    expect($user->isTrialExpired())->toBeFalse();
});

it('says trial is active during the trial window', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(3),
    ]);

    expect($user->isTrialActive())->toBeTrue();
    expect($user->isTrialExpired())->toBeFalse();
});

it('says trial is expired after trial_days have elapsed', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(8),
    ]);

    expect($user->isTrialActive())->toBeFalse();
    expect($user->isTrialExpired())->toBeTrue();
});

it('honours trial_days_override over the tier default', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(10),
        'trial_days_override' => 30,
    ]);

    expect($user->effectiveTrialDays())->toBe(30);
    expect($user->isTrialActive())->toBeTrue();
    expect($user->isTrialExpired())->toBeFalse();
});

it('falls back to the tier trial_days when override is null', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(3),
        'trial_days_override' => null,
    ]);

    expect($user->effectiveTrialDays())->toBe(7);
    expect($user->isTrialActive())->toBeTrue();
});

it('treats trial_days_override of zero as no trial granted', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now(),
        'trial_days_override' => 0,
    ]);

    expect($user->effectiveTrialDays())->toBe(0);
    expect($user->isTrialActive())->toBeFalse();
});

it('reports the wallet covers the next renewal when balance >= monthly rate', function (): void {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'wallet_balance_usdt' => 75,
    ]);

    expect($user->subscriptionCoversNextRenewal())->toBeTrue();
    expect($user->renewalShortfallUsdt())->toEqual(0.0);
});

it('reports a shortfall when wallet is below the monthly rate', function (): void {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'wallet_balance_usdt' => 30,
    ]);

    expect($user->subscriptionCoversNextRenewal())->toBeFalse();
    expect($user->renewalShortfallUsdt())->toEqual(45.0);
});

it('reports covered when no monthly rate is set (zero-rate tier)', function (): void {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 0.0)->id,
        'wallet_balance_usdt' => 0,
    ]);

    expect($user->subscriptionCoversNextRenewal())->toBeTrue();
    expect($user->renewalShortfallUsdt())->toEqual(0.0);
});

it('flags closing-mode when paused, regardless of trial state', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(2),
        'subscription_paused_at' => now(),
        'subscription_renews_at' => now()->addDays(20),
    ]);

    expect($user->isPaused())->toBeTrue();
    expect($user->isInClosingMode())->toBeTrue();
});

it('does not flag closing-mode while the trial is active', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 0,
        'trial_started_at' => now()->subDay(),
    ]);

    expect($user->isInClosingMode())->toBeFalse();
});

it('flags closing-mode when post-trial and renews_at is null', function (): void {
    $tier = tierFor('starter', 75, trialDays: 7);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(8),
        'subscription_renews_at' => null,
    ]);

    expect($user->isInClosingMode())->toBeTrue();
});

it('flags closing-mode when renews_at is in the past', function (): void {
    $tier = tierFor('starter', 75);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(30),
        'subscription_renews_at' => now()->subDay(),
    ]);

    expect($user->isInClosingMode())->toBeTrue();
});

it('does not flag closing-mode when renews_at is in the future', function (): void {
    $tier = tierFor('starter', 75);

    $user = User::factory()->create([
        'subscription_id' => $tier->id,
        'trial_started_at' => now()->subDays(30),
        'subscription_renews_at' => now()->addDays(15),
    ]);

    expect($user->isInClosingMode())->toBeFalse();
});

it('pause sets subscription_paused_at and is idempotent', function (): void {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'subscription_renews_at' => now()->addDays(20),
    ]);

    $user->pause();
    $first = $user->refresh()->subscription_paused_at;

    expect($first)->not->toBeNull();
    expect($user->isPaused())->toBeTrue();

    // Calling pause again leaves the original timestamp untouched.
    $user->pause();
    expect($user->refresh()->subscription_paused_at?->toIso8601String())
        ->toBe($first->toIso8601String());
});

it('resume clears paused_at and pushes renews_at by the pause duration', function (): void {
    $originalAnchor = now()->addDays(15);

    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'subscription_renews_at' => $originalAnchor,
        'subscription_paused_at' => now()->subDays(3),
    ]);

    $user->resume();
    $user->refresh();

    expect($user->isPaused())->toBeFalse();
    expect($user->subscription_paused_at)->toBeNull();

    $expected = $originalAnchor->copy()->addDays(3);
    expect($user->subscription_renews_at->toDateString())
        ->toBe($expected->toDateString());
});

it('resume is a no-op when the user is not paused', function (): void {
    $anchor = now()->addDays(15);

    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'subscription_renews_at' => $anchor,
        'subscription_paused_at' => null,
    ]);

    $user->resume();
    $user->refresh();

    expect($user->subscription_renews_at->toDateString())
        ->toBe($anchor->toDateString());
});

it('resume leaves a null renews_at as null but still clears paused_at', function (): void {
    $user = User::factory()->create([
        'subscription_id' => tierFor('starter', 75)->id,
        'subscription_renews_at' => null,
        'subscription_paused_at' => now()->subDays(3),
    ]);

    $user->resume();
    $user->refresh();

    expect($user->subscription_paused_at)->toBeNull();
    expect($user->subscription_renews_at)->toBeNull();
});
