<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/**
 * Event-on-activation: when an account's `can_trade` flips from false
 * to true AND `Account::isReadyToTrade()` clears, the
 * `AccountObserver::updated` hook dispatches a `PreparePositionsOpeningJob`
 * immediately. Cuts the wall-clock time from "user completes
 * registration" to "first trade attempt" by up to 3 minutes (the
 * `kraite:cron-create-positions` tick interval).
 *
 * The dispatch lands in the `trading_steps` table set (the cron uses
 * the same `Steps::usingPrefix('trading', ...)` block) and is deduped
 * against any already-pending parent job for the same account.
 */
function freshActivatableAccount(bool $startReady = false): Account
{
    $subscription = Subscription::firstOrCreate(
        ['canonical' => 'basic'],
        ['name' => 'Basic', 'monthly_rate_usdt' => '75.0000', 'trial_days' => 7, 'max_accounts' => 1]
    );

    $user = User::factory()->create([
        'subscription_id' => $subscription->id,
        'is_active' => true,
        'can_trade' => true,
        'wallet_balance_usdt' => '100.0000',
        'subscription_renews_at' => now()->addDays(30),
    ]);

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'can_trade' => $startReady,
    ]);

    if ($subscription->max_accounts === 1) {
        $user->update(['active_account_id' => $account->id]);
    }

    return $account;
}

function pendingPreparePositionsCount(Account $account): int
{
    return Steps::usingPrefix('trading', static fn (): int => Step::query()
        ->forClasses(PreparePositionsOpeningJob::class)
        ->forRelatable($account)
        ->where('state', Pending::class)
        ->count()
    );
}

it('dispatches PreparePositionsOpeningJob when can_trade flips false to true', function (): void {
    $account = freshActivatableAccount(startReady: false);

    expect(pendingPreparePositionsCount($account))->toBe(0);

    $account->update(['can_trade' => true]);

    expect(pendingPreparePositionsCount($account))->toBe(1);
});

it('does not dispatch when can_trade was already true (no false->true transition)', function (): void {
    $account = freshActivatableAccount(startReady: true);

    expect(pendingPreparePositionsCount($account))->toBe(0);

    // Touch some other column; can_trade did not transition.
    $account->update(['margin_percentage_long' => '50.0000']);

    expect(pendingPreparePositionsCount($account))->toBe(0);
});

it('does not dispatch when can_trade flips true but the user is paused', function (): void {
    $account = freshActivatableAccount(startReady: false);
    $account->user->update(['subscription_paused_at' => now()]);

    $account->update(['can_trade' => true]);

    expect(pendingPreparePositionsCount($account))->toBe(0);
});

it('does not dispatch a second time when another pending step already exists', function (): void {
    $account = freshActivatableAccount(startReady: false);

    // Pre-seed a pending parent job exactly as the cron / observer would have.
    Steps::usingPrefix('trading', function () use ($account): void {
        Step::create([
            'class' => PreparePositionsOpeningJob::class,
            'queue' => 'cronjobs',
            'relatable_type' => $account->getMorphClass(),
            'relatable_id' => $account->getKey(),
            'arguments' => ['accountId' => $account->id],
        ]);
    });

    expect(pendingPreparePositionsCount($account))->toBe(1);

    $account->update(['can_trade' => true]);

    expect(pendingPreparePositionsCount($account))->toBe(1);
});
