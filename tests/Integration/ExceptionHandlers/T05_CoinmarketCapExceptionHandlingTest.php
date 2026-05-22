<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestCoinmarketCapApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'coinmarketcap');

beforeEach(function (): void {
    // Create Engine admin record (required for forbidden hostname notifications)
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
});

it('cleans laravel.log', function (): void {
    file_put_contents(storage_path('logs/laravel.log'), '');
    expect(true)->toBe(true);
});

describe('CoinmarketCapExceptionHandler - Rate Limits', function (): void {
    it('handles 429/1008 minute rate limit by setting dispatch_after', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 429 with 1008 (minute rate limit)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcRateLimitedMinute',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_429_1008_minute_limit')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
        expect($step->dispatch_after->isFuture())->toBeTrue();
    });

    it('handles 429/1011 IP rate limit by setting dispatch_after', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 429 with 1011 (IP rate limit)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcRateLimitedIp',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_429_1011_ip_limit')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
        expect($step->dispatch_after->isFuture())->toBeTrue();
    });

    it('handles 429/1009 daily rate limit by setting dispatch_after', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 429 with 1009 (daily cap)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcRateLimitedDaily',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_429_1009_daily_limit')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
        expect($step->dispatch_after->isFuture())->toBeTrue();
    });

    it('handles 429/1010 monthly rate limit by setting dispatch_after', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 429 with 1010 (monthly cap)
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcRateLimitedMonthly',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_429_1010_monthly_limit')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
        expect($step->dispatch_after->isFuture())->toBeTrue();
    });
});

describe('CoinmarketCapExceptionHandler - Ignorable Errors', function (): void {
    it('handles 400 bad request as ignorable (skipped)', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 400
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcIgnorableBadRequest',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'completed'],
            ])
            ->withLabel('cmc_400_ignorable')
            ->test();

        // Assert: Step is completed (ignorable errors complete the step)
        $step->refresh();
        expect($step->state->value())->toBe('completed');
    });
});

describe('CoinmarketCapExceptionHandler - Retryable Errors', function (): void {
    it('handles 500 internal server error as retryable', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 500
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcServerError',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_500_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });

    it('handles 503 service unavailable as retryable', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 503
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcServiceUnavailable',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_503_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });
});

describe('CoinmarketCapExceptionHandler - Forbidden Errors (Account Blocked)', function (): void {
    it('handles 401/1001 invalid API key by creating forbidden_hostname with type account_blocked', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 401 with 1001
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcAccountBlocked',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_401_1001_account_blocked')
            ->test();

        // Assert: Step is pending (will retry on another worker)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: ForbiddenHostname entry created with account_id and type account_blocked
        $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
            ->where('api_system_id', $apiSystem->id)
            ->first();

        expect($forbiddenHostname)->not->toBeNull();
        expect($forbiddenHostname->account_id)->toBe($account->id);
        expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_ACCOUNT_BLOCKED);
    });

    it('handles 401/1002 missing API key by creating forbidden_hostname with type account_blocked', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 401 with 1002
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcMissingApiKey',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_401_1002_account_blocked')
            ->test();

        // Assert: Step is pending (will retry on another worker)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: ForbiddenHostname entry created with account_id and type account_blocked
        $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
            ->where('api_system_id', $apiSystem->id)
            ->first();

        expect($forbiddenHostname)->not->toBeNull();
        expect($forbiddenHostname->account_id)->toBe($account->id);
        expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_ACCOUNT_BLOCKED);
    });

    it('handles 402/1003 payment required by creating forbidden_hostname with type account_blocked', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 402 with 1003
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcPaymentRequired',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_402_1003_account_blocked')
            ->test();

        // Assert: Step is pending (will retry on another worker)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: ForbiddenHostname entry created with account_id and type account_blocked
        $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
            ->where('api_system_id', $apiSystem->id)
            ->first();

        expect($forbiddenHostname)->not->toBeNull();
        expect($forbiddenHostname->account_id)->toBe($account->id);
        expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_ACCOUNT_BLOCKED);
    });

    it('handles 403/1007 key disabled by creating forbidden_hostname with type account_blocked', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 403 with 1007
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'cmcKeyDisabled',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_403_1007_account_blocked')
            ->test();

        // Assert: Step is pending (will retry on another worker)
        $step->refresh();
        expect($step->state->value())->toBe('pending');

        // Assert: ForbiddenHostname entry created with account_id and type account_blocked
        $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
            ->where('api_system_id', $apiSystem->id)
            ->first();

        expect($forbiddenHostname)->not->toBeNull();
        expect($forbiddenHostname->account_id)->toBe($account->id);
        expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_ACCOUNT_BLOCKED);
    });
});

describe('CoinmarketCapExceptionHandler - Network Errors', function (): void {
    it('handles network connection errors as retryable', function (): void {
        // Arrange: Create CoinMarketCap account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'coinmarketcap']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws ConnectException
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_connect_exception' => true,
                'exception_message' => 'Connection timeout',
            ]],
        ], TestCoinmarketCapApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('cmc_connect_exception')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });
});
