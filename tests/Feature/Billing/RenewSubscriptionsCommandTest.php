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

function tier(string $canonical, float $monthly, int $trialDays = 7): Subscription
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

function billable(
    float $balance,
    ?int $tierId = null,
    ?\DateTimeInterface $trialStart = null,
    ?\DateTimeInterface $renewsAt = null,
    ?\DateTimeInterface $pausedAt = null,
): User {
    return User::factory()->create([
        'is_active' => true,
        'subscription_id' => $tierId ?? tier('starter', 75)->id,
        'wallet_balance_usdt' => $balance,
        'trial_started_at' => $trialStart,
        'subscription_renews_at' => $renewsAt,
        'subscription_paused_at' => $pausedAt,
    ]);
}

it('renews a user whose anchor is due and writes the ledger row', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: now()->subDay(),
    );

    $exit = $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect($exit)->toBe(0);
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(125.0);

    $tx = WalletTransaction::where('user_id', $user->id)->sole();
    expect($tx->type)->toBe(WalletTransaction::TYPE_DEBIT_SUBSCRIPTION);
    expect((float) $tx->amount_usdt)->toEqual(-75.0);
    expect((float) $tx->balance_after)->toEqual(125.0);
    expect($tx->meta['subscription_canonical'])->toBe('starter');
});

it('pushes the renewal anchor forward by exactly one month after a successful renewal', function () {
    $tier = tier('starter', 75);
    $anchor = now()->subDay();
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: $anchor,
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    $expected = $anchor->copy()->addMonth();
    expect($user->refresh()->subscription_renews_at->toDateString())
        ->toBe($expected->toDateString());
});

it('skips users whose anchor is still in the future', function () {
    $tier = tier('starter', 75);
    $anchor = now()->addDays(15);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: $anchor,
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(200.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('skips users currently in their trial window', function () {
    $tier = tier('starter', 75, trialDays: 7);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(3),
        renewsAt: now()->addDays(4),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(200.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('skips paused users', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: null,
        pausedAt: now(),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(200.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('fires closing-mode notification when balance cannot cover the monthly rate', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 30,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: now()->subDay(),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(30.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);

    Notification::assertSentTo(
        $user,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_closing_mode',
    );
});

it('fires low-balance pre-warning 7 days before renewal when wallet is short', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 30,
        tierId: $tier->id,
        trialStart: now()->subDays(20),
        renewsAt: now()->addDays(7),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    Notification::assertSentTo(
        $user,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_low_balance',
    );
});

it('does not fire low-balance pre-warning when wallet covers the next renewal', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(20),
        renewsAt: now()->addDays(7),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    Notification::assertNothingSent();
});

it('does not fire low-balance pre-warning outside the 7-day window', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 30,
        tierId: $tier->id,
        trialStart: now()->subDays(20),
        renewsAt: now()->addDays(15),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    Notification::assertNothingSent();
});

it('fires trial-ending pre-warning 2 days before trial expiry when wallet is short', function () {
    $tier = tier('starter', 75, trialDays: 7);
    $user = billable(
        balance: 30,
        tierId: $tier->id,
        trialStart: now()->subDays(5),
        renewsAt: now()->addDays(2),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    Notification::assertSentTo(
        $user,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_trial_ending',
    );
});

it('does not fire trial-ending pre-warning when wallet already covers first renewal', function () {
    $tier = tier('starter', 75, trialDays: 7);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(5),
        renewsAt: now()->addDays(2),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    Notification::assertNothingSent();
});

it('processes multiple billable users independently in one run', function () {
    $tier = tier('starter', 75);

    $okUser = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: now()->subDay(),
    );

    $brokeUser = billable(
        balance: 30,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: now()->subDay(),
    );

    $trialUser = billable(
        balance: 0,
        tierId: $tier->id,
        trialStart: now()->subDay(),
        renewsAt: now()->addDays(6),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $okUser->refresh()->wallet_balance_usdt)->toEqual(125.0);
    expect((float) $brokeUser->refresh()->wallet_balance_usdt)->toEqual(30.0);
    expect((float) $trialUser->refresh()->wallet_balance_usdt)->toEqual(0.0);

    Notification::assertSentTo(
        $brokeUser,
        AlertNotification::class,
        fn (AlertNotification $n) => $n->canonical === 'subscription_closing_mode',
    );
});

it('skips inactive users entirely', function () {
    $tier = tier('starter', 75);
    $user = User::factory()->create([
        'is_active' => false,
        'subscription_id' => $tier->id,
        'wallet_balance_usdt' => 200,
        'subscription_renews_at' => now()->subDay(),
    ]);

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(200.0);
});

it('skips users with no subscription assigned', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'subscription_id' => null,
        'wallet_balance_usdt' => 200,
    ]);

    $this->artisan('kraite:cron-renew-subscriptions')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(200.0);
});

it('writes nothing in dry-run mode', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 200,
        tierId: $tier->id,
        trialStart: now()->subDays(30),
        renewsAt: now()->subDay(),
    );

    $this->artisan('kraite:cron-renew-subscriptions --dry-run')->run();

    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(200.0);
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(0);
});

it('reads the monthly rate live from the subscriptions table on each run', function () {
    $tier = tier('starter', 75);
    $user = billable(
        balance: 500,
        tierId: $tier->id,
        trialStart: now()->subDays(60),
        renewsAt: now()->subDay(),
    );

    $this->artisan('kraite:cron-renew-subscriptions')->run();
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(425.0);

    // Tier rate changes mid-flight (Christmas promo etc.)
    $tier->update(['monthly_rate_usdt' => 30.0]);

    // Push the anchor back into "due" so the second run picks it up.
    $user->update(['subscription_renews_at' => now()->subDay()]);

    $this->artisan('kraite:cron-renew-subscriptions')->run();
    expect((float) $user->refresh()->wallet_balance_usdt)->toEqual(395.0);
});
