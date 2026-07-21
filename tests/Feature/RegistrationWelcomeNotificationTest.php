<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\NotificationMessageBuilder;
use Kraite\Core\Support\RegistrationWelcomeNotifier;

function registrationWelcomeAccount(bool $hasExistingActivity = false): Account
{
    $subscription = Subscription::firstOrCreate(
        ['canonical' => 'registration-welcome-basic'],
        [
            'name' => 'Registration Welcome Basic',
            'monthly_rate_usdt' => '75.0000',
            'trial_days' => 7,
            'max_accounts' => 1,
        ],
    );
    $user = User::factory()->create([
        'name' => 'Welcome Test Trader',
        'email' => 'registration-welcome-trader@kraite.test',
        'subscription_id' => $subscription->id,
        'subscription_renews_at' => now()->addDays(7),
        'is_active' => true,
        'can_trade' => true,
        'notifications_enabled' => true,
    ]);
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'name' => 'Bitget',
        'canonical' => 'bitget-registration-welcome',
    ]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
        'name' => 'Bitget Account',
        'is_active' => true,
        'can_trade' => true,
        'allow_other_positions' => false,
        'allow_other_orders' => $hasExistingActivity,
    ]);

    $user->update(['active_account_id' => $account->id]);

    return $account->fresh(['apiSystem', 'user']);
}

beforeEach(function (): void {
    config([
        'kraite.admin_url' => 'https://admin.kraite.test',
        'kraite.notifications_enabled' => true,
    ]);

    Kraite::findOrFail(1)->update(['notifications_enabled' => true]);
});

it('registers the welcome email in the notification catalogue', function (): void {
    $notification = Notification::query()
        ->where('canonical', 'registration_welcome')
        ->sole();

    expect($notification->title)->toBe('Welcome to Kraite')
        ->and($notification->description)->toBe('Email sent after a newly registered trading account is activated')
        ->and($notification->default_severity?->value)->toBe('info')
        ->and($notification->verified)->toBeTrue()
        ->and($notification->is_active)->toBeTrue()
        ->and($notification->cache_duration)->toBe(0)
        ->and($notification->cache_key)->toBeNull();
});

it('builds the standard welcome, activation explanation, and risk disclosure', function (): void {
    $user = User::factory()->make(['name' => 'Bruno']);

    $message = NotificationMessageBuilder::build('registration_welcome', [
        'exchange' => 'bitget',
        'has_existing_activity' => false,
        'dashboard_url' => 'https://admin.kraite.test',
    ], $user);

    $blocks = collect($message['emailBlocks']);
    $exchangeState = $blocks->firstWhere('label', 'Exchange ready');
    $riskDisclosure = $blocks->firstWhere('label', 'Trading risk');

    expect($message['title'])->toBe('Welcome to Kraite')
        ->and($blocks->first())->toBe([
            'type' => 'heading',
            'text' => 'Welcome to Kraite, Bruno!',
            'size' => 'md',
        ])
        ->and($blocks->pluck('text')->filter()->implode(' '))->toContain('We are genuinely happy to have you trading with us.')
        ->and($blocks->pluck('text')->filter()->implode(' '))->toContain('Kraite starts on the next trading cycle')
        ->and($exchangeState)->toBe([
            'type' => 'callout',
            'variant' => 'success',
            'label' => 'Exchange ready',
            'body' => 'We did not detect existing positions or limit orders in your Bitget futures account. Kraite can work with your available futures balance immediately.',
        ])
        ->and($riskDisclosure['variant'])->toBe('danger')
        ->and($riskDisclosure['body'])->toContain('You can lose some or all of the money allocated to trading.')
        ->and($riskDisclosure['body'])->toContain('not financial advice')
        ->and($blocks->firstWhere('type', 'button'))->toBe([
            'type' => 'button',
            'href' => 'https://admin.kraite.test',
            'label' => 'Open your dashboard',
            'variant' => 'primary',
        ]);
});

it('warns when registration detected an existing position or limit order', function (): void {
    $message = NotificationMessageBuilder::build('registration_welcome', [
        'exchange' => 'bitget',
        'has_existing_activity' => true,
        'dashboard_url' => 'https://admin.kraite.test',
    ], User::factory()->make(['name' => 'Trader']));

    $existingActivity = collect($message['emailBlocks'])->firstWhere('label', 'Existing trades detected');

    expect($existingActivity['variant'])->toBe('warning')
        ->and($existingActivity['body'])->toContain('We detected open positions or limit orders in your Bitget futures account.')
        ->and($existingActivity['body'])->toContain('Kraite will not touch them')
        ->and($existingActivity['body'])->toContain('works best when it starts with no existing positions or limit orders');
});

it('sends the welcome once through the mail channel with the account activity result', function (): void {
    $account = registrationWelcomeAccount(hasExistingActivity: true);
    $user = $account->user;

    NotificationFacade::fake();

    $sent = app(RegistrationWelcomeNotifier::class)->send($account);

    expect($sent)->toBeTrue();

    NotificationFacade::assertSentTo(
        $user,
        AlertNotification::class,
        function (AlertNotification $notification, array $channels) use ($account): bool {
            $warning = collect($notification->emailBlocks)->firstWhere('label', 'Existing trades detected');

            return $channels === ['mail']
                && $notification->canonical === 'registration_welcome'
                && $notification->relatable?->is($account)
                && $warning['body'] === 'We detected open positions or limit orders in your Bitget futures account. Kraite will not touch them and will use only your available balance. The bot works best when it starts with no existing positions or limit orders. Close or cancel them when appropriate; Kraite will detect when your account is clear.';
        },
    );
});

it('does not resend when the welcome already has a mail audit record', function (): void {
    $account = registrationWelcomeAccount();
    $user = $account->user;
    NotificationLog::factory()->mail()->delivered()->create([
        'canonical' => 'registration_welcome',
        'notification_id' => Notification::query()->where('canonical', 'registration_welcome')->value('id'),
        'user_id' => $user->id,
        'relatable_type' => $account->getMorphClass(),
        'relatable_id' => $account->id,
        'recipient' => $user->email,
    ]);

    NotificationFacade::fake();

    expect(app(RegistrationWelcomeNotifier::class)->send($account))->toBeFalse();

    NotificationFacade::assertNothingSent();
});

it('retries when the previous welcome mail attempt failed', function (): void {
    $account = registrationWelcomeAccount();
    $user = $account->user;
    NotificationLog::factory()->mail()->failed()->create([
        'canonical' => 'registration_welcome',
        'notification_id' => Notification::query()->where('canonical', 'registration_welcome')->value('id'),
        'user_id' => $user->id,
        'relatable_type' => $account->getMorphClass(),
        'relatable_id' => $account->id,
        'recipient' => $user->email,
    ]);

    NotificationFacade::fake();

    expect(app(RegistrationWelcomeNotifier::class)->send($account))->toBeTrue();

    NotificationFacade::assertSentTo($user, AlertNotification::class);
});

it('does not welcome an account that is no longer ready to trade', function (): void {
    $account = registrationWelcomeAccount();
    $account->forceFill(['can_trade' => false])->saveQuietly();

    NotificationFacade::fake();

    expect(app(RegistrationWelcomeNotifier::class)->send($account->fresh()))->toBeFalse();

    NotificationFacade::assertNothingSent();
});

it('does not abort activation when the welcome notification infrastructure fails', function (): void {
    $account = registrationWelcomeAccount();
    $defaultConnection = config('database.default');

    DB::shouldReceive('afterCommit')
        ->once()
        ->andReturnUsing(static fn (Closure $callback): mixed => $callback());
    Log::shouldReceive('channel')->once()->with('jobs')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    try {
        config(['database.default' => 'missing-registration-welcome-connection']);
        app(RegistrationWelcomeNotifier::class)->afterAccountActivated($account);
    } finally {
        config(['database.default' => $defaultConnection]);
    }
});

it('does not abort activation when after-commit scheduling fails', function (): void {
    $account = registrationWelcomeAccount();

    DB::shouldReceive('afterCommit')
        ->once()
        ->andThrow(new RuntimeException('after-commit unavailable'));
    Log::shouldReceive('channel')->once()->with('jobs')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    app(RegistrationWelcomeNotifier::class)->afterAccountActivated($account);
});
