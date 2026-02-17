<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Engine;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Tests\Support\StepTester;
use Tests\Support\TestBinanceApiableJob;
use Tests\Support\TestBybitApiableJob;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('integration', 'notifications', 'forbidden-hostname');

beforeEach(function () {
    config(['kraite.notifications_enabled' => true]);

    // Clear the once() cache for Engine::admin() to prevent test pollution
    // The once() helper memoizes results per process, so we need to flush it between tests
    Once::flush();

    // Create all required notification canonicals
    \Kraite\Core\Models\Notification::factory()->serverIpNotWhitelisted()->create();
    \Kraite\Core\Models\Notification::factory()->serverIpRateLimited()->create();
    \Kraite\Core\Models\Notification::factory()->serverIpBanned()->create();
    \Kraite\Core\Models\Notification::factory()->serverAccountBlocked()->create();

    // Create Engine admin record
    Engine::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
});

describe('User Notifications (Account-Specific Issues)', function () {
    it('sends server_ip_not_whitelisted notification to USER when IP not whitelisted', function () {
        Notification::fake();

        // Arrange: Create Binance account with user
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws IP not whitelisted error
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'binanceIpNotWhitelisted',
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('binance_ip_not_whitelisted_notification')
            ->test();

        // Assert: Notification sent to USER (not admin)
        Notification::assertSentTo(
            $user,
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_not_whitelisted';
            }
        );

        // Assert: Notification NOT sent to admin
        Notification::assertNotSentTo(
            Engine::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_not_whitelisted';
            }
        );
    });

    it('sends server_account_blocked notification to USER when account blocked', function () {
        Notification::fake();

        // Arrange: Create Bybit account with user
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws account blocked error
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'bybitAccountBlocked',
            ]],
        ], TestBybitApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('bybit_account_blocked_notification')
            ->test();

        // Assert: Notification sent to USER (not admin)
        Notification::assertSentTo(
            $user,
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_account_blocked';
            }
        );

        // Assert: Notification NOT sent to admin
        Notification::assertNotSentTo(
            Engine::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_account_blocked';
            }
        );
    });
});

describe('Admin Notifications (System-Wide Issues)', function () {
    it('sends server_ip_rate_limited notification to ADMIN when IP rate limited', function () {
        Notification::fake();

        // Arrange: Create Bybit account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws IP rate limited error
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'bybitIpRateLimited',
            ]],
        ], TestBybitApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('bybit_ip_rate_limited_notification')
            ->test();

        // Assert: Notification sent to ADMIN
        Notification::assertSentTo(
            Engine::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_rate_limited';
            }
        );

        // Assert: Notification NOT sent to user
        Notification::assertNotSentTo(
            $user,
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_rate_limited';
            }
        );
    });

    it('sends server_ip_banned notification to ADMIN when IP banned', function () {
        Notification::fake();

        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws IP banned error
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'binanceIpBanned',
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('binance_ip_banned_notification')
            ->test();

        // Assert: Notification sent to ADMIN
        Notification::assertSentTo(
            Engine::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_banned';
            }
        );

        // Assert: Notification NOT sent to user
        Notification::assertNotSentTo(
            $user,
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_banned';
            }
        );
    });
});

describe('Notification Data', function () {
    it('includes correct reference data in notification', function () {
        Notification::fake();

        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws account blocked error
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'binanceAccountBlocked',
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('binance_account_blocked_with_data')
            ->test();

        // Assert: Notification was sent with correct canonical
        Notification::assertSentTo(
            $user,
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_account_blocked';
            }
        );

        // Assert: ForbiddenHostname was created with correct data
        $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
            ->where('api_system_id', $apiSystem->id)
            ->first();

        expect($forbiddenHostname)->not->toBeNull();
        expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_ACCOUNT_BLOCKED);
    });
});

describe('Notification Deduplication', function () {
    it('does not send duplicate notification for same forbidden hostname', function () {
        Notification::fake();

        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create first step that throws account blocked error
        $step1 = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'binanceAccountBlocked',
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch first step (creates ForbiddenHostname)
        StepTester::withSteps([$step1])
            ->withStatusMatrix([
                1 => [$step1->id => 'pending'],
            ])
            ->withLabel('binance_account_blocked_first')
            ->test();

        // Assert: One notification sent
        Notification::assertSentToTimes($user, AlertNotification::class, 1);

        // Create second step with same error
        $step2 = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'binanceAccountBlocked',
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch second step (ForbiddenHostname already exists)
        StepTester::withSteps([$step2])
            ->withStatusMatrix([
                1 => [$step2->id => 'pending'],
            ])
            ->withLabel('binance_account_blocked_second')
            ->test();

        // Assert: Still only one notification (no duplicate)
        Notification::assertSentToTimes($user, AlertNotification::class, 1);
    });
});
