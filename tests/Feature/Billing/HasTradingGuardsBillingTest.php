<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Trading\Kraite as KraiteEngine;

uses(RefreshDatabase::class)->group('billing', 'guard');

beforeEach(function () {
    KraiteModel::firstOrCreate(
        ['id' => 1],
        [
            'allow_opening_positions' => true,
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'k',
            'notification_channels' => ['pushover'],
        ],
    );
});

function starterTier(float $rate = 2.5): Subscription
{
    return Subscription::updateOrCreate(
        ['canonical' => 'starter'],
        [
            'name' => 'Starter',
            'daily_rate_usdt' => $rate,
            'trial_days' => 7,
            'max_accounts' => 1,
            'max_balance' => 10000,
            'is_active' => true,
        ],
    );
}

function unlimitedTier(float $rate = 5.0): Subscription
{
    return Subscription::updateOrCreate(
        ['canonical' => 'unlimited'],
        [
            'name' => 'Unlimited',
            'daily_rate_usdt' => $rate,
            'trial_days' => 7,
            'max_accounts' => null,
            'max_balance' => null,
            'is_active' => true,
        ],
    );
}

function billableUserWithAccount(Subscription $tier, float $balance, ?\DateTimeInterface $trialStart = null, ?int $activeAccountId = null): array
{
    $apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'name' => 'Binance',
        ]);

    $user = User::factory()->create([
        'is_active' => true,
        'can_trade' => true,
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => $balance,
        'trial_started_at' => $trialStart,
        'active_account_id' => $activeAccountId,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'can_trade' => true,
        'margin_mode' => 'CROSSED',
    ]);

    if ($activeAccountId === null) {
        $user->update(['active_account_id' => $account->id]);
    }

    return ['user' => $user->refresh(), 'account' => $account];
}

it('allows new opens when balance covers the daily rate and account is the active one', function () {
    $tier = starterTier(2.5);

    $f = billableUserWithAccount($tier, balance: 100);

    $engine = KraiteEngine::withAccount($f['account']);

    expect($engine->canOpenNewPositions())->toBeTrue();
});

it('blocks new opens when the user is in closing-mode (wallet < daily rate, no trial)', function () {
    $tier = starterTier(2.5);

    $f = billableUserWithAccount($tier, balance: 1.0);

    $engine = KraiteEngine::withAccount($f['account']);

    expect($engine->canOpenNewPositions())->toBeFalse();
});

it('does not block when trial is active even with empty wallet', function () {
    $tier = starterTier(2.5);

    $f = billableUserWithAccount(
        $tier,
        balance: 0,
        trialStart: now()->subDay(),
    );

    $engine = KraiteEngine::withAccount($f['account']);

    expect($engine->canOpenNewPositions())->toBeTrue();
});

it('blocks new opens on a Starter user’s non-active account', function () {
    $tier = starterTier(2.5);

    $apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'name' => 'Binance',
        ]);

    $user = User::factory()->create([
        'is_active' => true,
        'can_trade' => true,
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 100,
        'trial_started_at' => null,
    ]);

    $designated = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'can_trade' => true,
        'margin_mode' => 'CROSSED',
    ]);

    $secondary = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'can_trade' => true,
        'margin_mode' => 'CROSSED',
    ]);

    $user->update(['active_account_id' => $designated->id]);
    $user->refresh();

    expect(KraiteEngine::withAccount($designated->refresh())->canOpenNewPositions())->toBeTrue();
    expect(KraiteEngine::withAccount($secondary->refresh())->canOpenNewPositions())->toBeFalse();
});

it('does not apply the active-account gate on Unlimited tier', function () {
    $tier = unlimitedTier(5.0);

    $apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'name' => 'Binance',
        ]);

    $user = User::factory()->create([
        'is_active' => true,
        'can_trade' => true,
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 100,
        'trial_started_at' => null,
        'active_account_id' => null,
    ]);

    $accountA = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'can_trade' => true,
        'margin_mode' => 'CROSSED',
    ]);

    $accountB = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'can_trade' => true,
        'margin_mode' => 'CROSSED',
    ]);

    expect(KraiteEngine::withAccount($accountA->refresh())->canOpenNewPositions())->toBeTrue();
    expect(KraiteEngine::withAccount($accountB->refresh())->canOpenNewPositions())->toBeTrue();
});
