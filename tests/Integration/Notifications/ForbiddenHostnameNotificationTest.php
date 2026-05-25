<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Tests\Support\StepTester;
use Tests\Support\TestBinanceApiableJob;
use Tests\Support\TestBybitApiableJob;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('integration', 'notifications', 'forbidden-hostname');

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);

    // Clear the once() cache for Kraite::admin() to prevent test pollution
    // The once() helper memoizes results per process, so we need to flush it between tests
    Once::flush();

    // Create all required notification canonicals
    \Kraite\Core\Models\Notification::factory()->serverIpNotWhitelisted()->create();
    \Kraite\Core\Models\Notification::factory()->serverIpRateLimited()->create();
    \Kraite\Core\Models\Notification::factory()->serverIpBanned()->create();
    \Kraite\Core\Models\Notification::factory()->serverAccountBlocked()->create();

    // Create Engine admin record
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
});

describe('User Notifications (Account-Specific Issues)', function (): void {
    it('sends server_ip_not_whitelisted notification to USER when IP not whitelisted', function (): void {
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
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_ip_not_whitelisted';
            }
        );
    });

    it('sends server_account_blocked notification to USER when account blocked', function (): void {
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
            Kraite::admin(),
            AlertNotification::class,
            function ($notification) {
                return $notification->canonical === 'server_account_blocked';
            }
        );
    });
});

describe('Admin Notifications (System-Wide Issues)', function (): void {
    it('sends server_ip_rate_limited notification to ADMIN when IP rate limited', function (): void {
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
            Kraite::admin(),
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

    it('sends server_ip_banned notification to ADMIN when IP banned', function (): void {
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
            Kraite::admin(),
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

describe('Notification Data', function (): void {
    it('includes correct reference data in notification', function (): void {
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

// Note: the previous "Notification Deduplication" describe block was
// dropped during the v1.50.0 → v1.51.0 architectural shift. That test
// exercised the rotation-era pre-flight cascade in BaseApiableJob::compute()
// which has been removed — routing decisions (and the associated
// `account_all_workers_blacklisted` notification) now happen at dispatch
// time inside `Kraite\Core\Support\StepRouter` and are covered by the
// `tests/Integration/Routing/StepRouterTest.php` suite. The per-ban
// `server_account_blocked` observer dedup intent is covered by
// `tests/Integration/Rotation/ForbiddenBanTtlTest.php` (the updateOrCreate
// upsert behaviour with refreshed forbidden_until on re-detection).
