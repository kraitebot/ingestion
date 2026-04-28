<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Notifications\AlertNotification;

uses(RefreshDatabase::class)->group('billing', 'cron');

beforeEach(function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
});

function tier(string $canonical, float $rate, int $trialDays = 7): Subscription
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

function billable(float $balance, ?int $tierId = null, ?\DateTimeInterface $trialStart = null): User
{
    return User::factory()->create([
        'is_active' => true,
        'subscription_id' => $tierId ?? tier('starter', 2.5)->id,
        'wallet_balance_usdt' => $balance,
        'trial_started_at' => $trialStart,
    ]);
}

it('debits the daily rate from a billable user and writes the ledger row', function () {
    $tier = tier('starter', 2.5);
    $user = billable(balance: 100, tierId: $tier->id);

    $exit = $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect($exit)->toBe(0);
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(97.5);

    $tx = WalletTransaction::where('user_id', $user->id)->first();
    expect($tx)->not->toBeNull();
    expect($tx->type)->toBe(WalletTransaction::TYPE_DEBIT_SUBSCRIPTION);
    expect((float) $tx->amount_usdt)->toEqual(-2.5);
    expect((float) $tx->balance_after)->toEqual(97.5);
    expect($tx->meta['subscription_canonical'])->toBe('starter');
});

it('skips users currently in their trial window', function () {
    $tier = tier('starter', 2.5, trialDays: 7);
    $user = billable(balance: 100, tierId: $tier->id, trialStart: now()->subDays(3));

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(100.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('debits a user whose trial has expired', function () {
    $tier = tier('starter', 2.5, trialDays: 7);
    $user = billable(balance: 50, tierId: $tier->id, trialStart: now()->subDays(8));

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(47.5);
});

it('fires a closing-mode notification when balance cannot cover the daily rate', function () {
    $tier = tier('starter', 2.5);
    $user = billable(balance: 1.0, tierId: $tier->id);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(1.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);

    Notification::assertSentTo(
        $user,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_closing_mode',
    );
});

it('fires a low-balance notification when post-debit runway drops under 7 days', function () {
    $tier = tier('starter', 2.5);
    // Balance 12.5 → after debit 10 → 10/2.5 = 4 days runway → < 7
    $user = billable(balance: 12.5, tierId: $tier->id);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    Notification::assertSentTo(
        $user,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_low_balance',
    );
});

it('does not fire low-balance when post-debit runway is comfortably above 7 days', function () {
    $tier = tier('starter', 2.5);
    // Balance 100 → after 97.5 → 39 days runway
    $user = billable(balance: 100, tierId: $tier->id);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    Notification::assertNothingSent();
});

it('processes multiple billable users independently in one run', function () {
    $tier = tier('starter', 2.5);

    $okUser = billable(balance: 100, tierId: $tier->id);
    $brokeUser = billable(balance: 0.5, tierId: $tier->id);
    $trialUser = billable(balance: 1.0, tierId: $tier->id, trialStart: now()->subDay());

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect((float) $okUser->refresh()->wallet_balance_usdt)->toEqual(97.5);
    expect((float) $brokeUser->refresh()->wallet_balance_usdt)->toEqual(0.5);
    expect((float) $trialUser->refresh()->wallet_balance_usdt)->toEqual(1.0);

    Notification::assertSentTo(
        $brokeUser,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_closing_mode',
    );
});

it('skips inactive users entirely', function () {
    $tier = tier('starter', 2.5);
    $user = User::factory()->create([
        'is_active' => false,
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 100,
    ]);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(100.0);
});

it('skips users with no subscription assigned', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'subscription_id' => null,
        'wallet_balance_usdt' => 100,
    ]);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(100.0);
});

it('writes nothing in dry-run mode', function () {
    $tier = tier('starter', 2.5);
    $user = billable(balance: 100, tierId: $tier->id);

    $this->artisan('kraite:cron-deduct-subscriptions --dry-run')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(100.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('reads the daily rate live from the subscriptions table on each run', function () {
    $tier = tier('starter', 2.5);
    $user = billable(balance: 100, tierId: $tier->id);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(97.5);

    // Tier rate changes mid-flight (Christmas promo etc.)
    $tier->update(['daily_rate_usdt' => 1.0]);

    $this->artisan('kraite:cron-deduct-subscriptions')->run();
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(96.5);
});
