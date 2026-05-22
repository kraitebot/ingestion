<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestBinanceApiableJob;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('integration', 'forbidden-hostname', 'blocking');

/*
|--------------------------------------------------------------------------
| Pre-existing ForbiddenHostname Blocking Tests
|--------------------------------------------------------------------------
|
| These tests verify that BaseApiableJob::compute() correctly detects
| pre-existing ForbiddenHostname records and retries the job WITHOUT
| calling computeApiable() (i.e., no API call is made).
|
*/

describe('Account-Specific Bans (IP Not Whitelisted, Account Blocked)', function (): void {
    it('skips API call and retries when account has ip_not_whitelisted ban', function (): void {
        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create ForbiddenHostname (user forgot to whitelist IP)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            'forbidden_until' => null, // Permanent until user fixes
        ]);

        // Create step that would succeed (no exception stub)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                // No throw_exception_stub - job would succeed normally
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'], // Should stay pending (retried)
            ])
            ->withLabel('forbidden_ip_not_whitelisted_blocks')
            ->test();

        // Assert: Step is back to pending (retried)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: computeApiable was NEVER called (API call skipped)
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->not->toContain('computeApiable:start');
        expect($events)->not->toContain('computeApiable:success');
    });

    it('skips API call and retries when account has account_blocked ban', function (): void {
        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create ForbiddenHostname (API key revoked/disabled)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account->id,
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
            'forbidden_until' => null, // Permanent until user fixes
        ]);

        // Create step that would succeed (no exception stub)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('forbidden_account_blocked_blocks')
            ->test();

        // Assert: Step is back to pending (retried)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: computeApiable was NEVER called
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->not->toContain('computeApiable:start');
    });

    it('does NOT block other accounts when ban is account-specific', function (): void {
        // Arrange: Create Binance API system
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);

        // Create two users with two accounts
        $user1 = User::factory()->create();
        $account1 = Account::factory()->create([
            'user_id' => $user1->id,
            'api_system_id' => $apiSystem->id,
        ]);

        $user2 = User::factory()->create();
        $account2 = Account::factory()->create([
            'user_id' => $user2->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create ForbiddenHostname ONLY for account1
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => $account1->id,
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            'forbidden_until' => null,
        ]);

        // Create step for account2 (not banned)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account2->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step for account2
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'completed'], // Should complete (not blocked)
            ])
            ->withLabel('account_specific_ban_does_not_block_others')
            ->test();

        // Assert: Step completed (not blocked by account1's ban)
        $step->refresh();
        expect($step->state->value())->toBe('completed');

        // Assert: computeApiable WAS called and succeeded
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->toContain('computeApiable:start');
        expect($events)->toContain('computeApiable:success');
    });
});

describe('System-Wide Bans (IP Rate Limited, IP Banned)', function (): void {
    it('skips API call and retries when system-wide ip_rate_limited ban exists', function (): void {
        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create system-wide ForbiddenHostname (temporary rate limit)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => null, // System-wide (affects ALL accounts)
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_RATE_LIMITED,
            'forbidden_until' => now()->addMinutes(10), // Temporary ban
        ]);

        // Create step that would succeed
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('forbidden_ip_rate_limited_blocks')
            ->test();

        // Assert: Step is back to pending
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: computeApiable was NEVER called
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->not->toContain('computeApiable:start');
    });

    it('skips API call and retries when system-wide ip_banned ban exists', function (): void {
        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create system-wide ForbiddenHostname (permanent ban)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => null, // System-wide
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_BANNED,
            'forbidden_until' => null, // Permanent
        ]);

        // Create step that would succeed
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('forbidden_ip_banned_blocks')
            ->test();

        // Assert: Step is back to pending
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: computeApiable was NEVER called
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->not->toContain('computeApiable:start');
    });

    it('blocks ALL accounts when system-wide ban exists', function (): void {
        // Arrange: Create Binance API system
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);

        // Create two different accounts
        $user1 = User::factory()->create();
        $account1 = Account::factory()->create([
            'user_id' => $user1->id,
            'api_system_id' => $apiSystem->id,
        ]);

        $user2 = User::factory()->create();
        $account2 = Account::factory()->create([
            'user_id' => $user2->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create system-wide ban (no account_id)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => null, // System-wide
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_BANNED,
            'forbidden_until' => null,
        ]);

        // Create steps for both accounts
        $step1 = StepTester::createSteps([
            ['arguments' => ['accountId' => $account1->id]],
        ], TestBinanceApiableJob::class)[0];

        $step2 = StepTester::createSteps([
            ['arguments' => ['accountId' => $account2->id]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch both steps
        StepTester::withSteps([$step1, $step2])
            ->withStatusMatrix([
                1 => [$step1->id => 'pending', $step2->id => 'pending'],
            ])
            ->withLabel('system_wide_ban_blocks_all')
            ->test();

        // Assert: Both steps are back to pending (both blocked)
        $step1->refresh();
        $step2->refresh();
        expect($step1->state->value())->toBe('pending');
        expect($step2->state->value())->toBe('pending');

        // Assert: Neither called computeApiable
        $events1 = array_column($step1->response['execution_path'] ?? [], 'event');
        $events2 = array_column($step2->response['execution_path'] ?? [], 'event');
        expect($events1)->not->toContain('computeApiable:start');
        expect($events2)->not->toContain('computeApiable:start');
    });
});

describe('Temporary Ban Expiry', function (): void {
    it('allows API call when temporary ban has expired', function (): void {
        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create EXPIRED ForbiddenHostname (forbidden_until is in the past)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => null,
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_RATE_LIMITED,
            'forbidden_until' => now()->subMinutes(5), // Expired 5 minutes ago
        ]);

        // Create step that would succeed
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'completed'], // Should complete (ban expired)
            ])
            ->withLabel('expired_ban_allows_api_call')
            ->test();

        // Assert: Step completed (not blocked)
        $step->refresh();
        expect($step->state->value())->toBe('completed');

        // Assert: computeApiable WAS called and succeeded
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->toContain('computeApiable:start');
        expect($events)->toContain('computeApiable:success');
    });

    it('blocks API call when temporary ban has NOT expired', function (): void {
        // Arrange: Create Binance account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Pre-create ACTIVE ForbiddenHostname (forbidden_until is in the future)
        ForbiddenHostname::create([
            'api_system_id' => $apiSystem->id,
            'account_id' => null,
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_RATE_LIMITED,
            'forbidden_until' => now()->addMinutes(5), // Expires in 5 minutes
        ]);

        // Create step that would succeed
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'], // Should stay pending (still banned)
            ])
            ->withLabel('active_ban_blocks_api_call')
            ->test();

        // Assert: Step is back to pending (blocked)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: computeApiable was NOT called
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->not->toContain('computeApiable:start');
    });
});

describe('Cross-Exchange Isolation', function (): void {
    it('does NOT block jobs for different API system', function (): void {
        // Arrange: Create both Binance and Bybit API systems
        $binanceSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
        $bybitSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);

        $user = User::factory()->create();

        // Create account for Binance
        $binanceAccount = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $binanceSystem->id,
        ]);

        // Pre-create ForbiddenHostname ONLY for Bybit
        ForbiddenHostname::create([
            'api_system_id' => $bybitSystem->id,
            'account_id' => null,
            'ip_address' => Kraite::ip(),
            'type' => ForbiddenHostname::TYPE_IP_BANNED,
            'forbidden_until' => null,
        ]);

        // Create step for Binance account (Bybit is banned, not Binance)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $binanceAccount->id,
            ]],
        ], TestBinanceApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'completed'], // Should complete (Binance not banned)
            ])
            ->withLabel('cross_exchange_isolation')
            ->test();

        // Assert: Step completed (Bybit ban doesn't affect Binance)
        $step->refresh();
        expect($step->state->value())->toBe('completed');

        // Assert: computeApiable WAS called
        $executionPath = $step->response['execution_path'] ?? [];
        $events = array_column($executionPath, 'event');
        expect($events)->toContain('computeApiable:start');
        expect($events)->toContain('computeApiable:success');
    });
});
