<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestBinanceApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'binance');

beforeEach(function () {
    // Create Engine admin record (required for forbidden hostname notifications)
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
});

it('cleans laravel.log', function () {
    file_put_contents(storage_path('logs/laravel.log'), '');
    expect(true)->toBe(true);
});

it('handles 429 rate limit by setting dispatch_after', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 429 exception
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceRateLimited',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step (rate limit will be triggered)
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step should be pending after rate limit
        ])
        ->withLabel('binance_429_rate_limit')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();

    // Verify execution path tracked rate limiting
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:start');
    expect($events)->toContain('handleApiException:handled');
});

it('handles 400/-1003 rate limit by setting dispatch_after', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 400 with vendor code -1003 (WAF limit)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceWafLimit',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('binance_400_waf_limit')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('handles 418 IP ban (permanent) by creating forbidden_hostname', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 418 (permanent IP ban - no Retry-After)
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
        ->withLabel('binance_418_ip_banned')
        ->test();

    // Assert: Step is pending and retried
    $step->refresh();
    expect($step->state->value())->toBe('pending');

    // Assert: ForbiddenHostname entry created with type ip_banned
    $forbiddenHostname = ForbiddenHostname::where('api_system_id', $apiSystem->id)
        ->first();

    expect($forbiddenHostname)->not->toBeNull();
    expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_IP_BANNED);
    expect($forbiddenHostname->account_id)->toBeNull(); // System-wide ban
    expect($forbiddenHostname->forbidden_until)->toBeNull(); // Permanent
});

it('handles 418 IP rate limit (temporary) by creating forbidden_hostname with forbidden_until', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 418 (temporary IP rate limit - with Retry-After)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceIpRateLimited',
            'throw_exception_stub_args' => [120],
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('binance_418_ip_rate_limited')
        ->test();

    // Assert: Step is pending
    $step->refresh();
    expect($step->state->value())->toBe('pending');

    // Assert: ForbiddenHostname entry created with type ip_rate_limited
    $forbiddenHostname = ForbiddenHostname::where('api_system_id', $apiSystem->id)
        ->first();

    expect($forbiddenHostname)->not->toBeNull();
    expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_IP_RATE_LIMITED);
    expect($forbiddenHostname->account_id)->toBeNull(); // System-wide
    expect($forbiddenHostname->forbidden_until)->not->toBeNull(); // Has expiry
    expect($forbiddenHostname->forbidden_until->isFuture())->toBeTrue();
});

it('handles 401/-2015 IP not whitelisted by creating account-specific forbidden_hostname', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 401/-2015 with IP in message (user forgot to whitelist)
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
        ->withLabel('binance_401_ip_not_whitelisted')
        ->test();

    // Assert: Step is pending
    $step->refresh();
    expect($step->state->value())->toBe('pending');

    // Assert: ForbiddenHostname entry created with type ip_not_whitelisted
    $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
        ->where('api_system_id', $apiSystem->id)
        ->first();

    expect($forbiddenHostname)->not->toBeNull();
    expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_IP_NOT_WHITELISTED);
    expect($forbiddenHostname->account_id)->toBe($account->id); // Account-specific
});

it('handles 401/-2015 account blocked by creating account-specific forbidden_hostname', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 401/-2015 without IP in message (API key issue)
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
        ->withLabel('binance_401_account_blocked')
        ->test();

    // Assert: Step is pending
    $step->refresh();
    expect($step->state->value())->toBe('pending');

    // Assert: ForbiddenHostname entry created with type account_blocked
    $forbiddenHostname = ForbiddenHostname::where('account_id', $account->id)
        ->where('api_system_id', $apiSystem->id)
        ->first();

    expect($forbiddenHostname)->not->toBeNull();
    expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_ACCOUNT_BLOCKED);
    expect($forbiddenHostname->account_id)->toBe($account->id); // Account-specific
});

it('ignores 400/-4046 exception and completes step', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 400/-4046 (No need to change margin type)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceIgnorableMarginType',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'], // Exception ignored, step completes
        ])
        ->withLabel('binance_400_4046_ignorable')
        ->test();

    // Assert: Step completed successfully (exception was ignored)
    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Verify execution path shows exception was handled (not rethrown)
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:start');
    expect($events)->toContain('handleApiException:handled');
    expect($events)->not->toContain('handleApiException:rethrow');
});

it('ignores 400/-5027 exception and completes step', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 400/-5027 (No need to modify order)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceIgnorableOrderModify',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->withLabel('binance_400_5027_ignorable')
        ->test();

    // Assert: Step completed successfully
    $step->refresh();
    expect($step->state->value())->toBe('completed');
});

it('retries 503 exception with backoff in dispatch_after', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 503 (Service Unavailable)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceServiceUnavailable',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Exception rethrown, BaseQueueableJob retries
        ])
        ->withLabel('binance_503_retryable')
        ->test();

    // Assert: Step is pending with dispatch_after set (backoff applied)
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1); // Retry count incremented

    // Verify execution path shows exception was rethrown
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:rethrow');
});

it('retries 400/-2013 exception with backoff in dispatch_after', function () {
    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 400/-2013 (Order does not exist)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceOrderNotFound',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('binance_400_2013_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('handles 400/-1021 recvWindow mismatch by updating recvwindow_margin', function () {
    // Fake notifications to prevent actual sending
    Illuminate\Support\Facades\Notification::fake();

    // Note: Engine admin is created in beforeEach, no need to recreate

    // Arrange: Create Binance account
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'binance',
        'recvwindow_margin' => 5000, // Initial value
    ]);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 400/-1021 (recvWindow mismatch)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceRecvWindowMismatch',
        ]],
    ], TestBinanceApiableJob::class)[0];

    // Act: Dispatch step (Artisan command will execute)
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step retries after handling recvWindow
        ])
        ->withLabel('binance_400_1021_recvwindow')
        ->test();

    // Assert: Step is pending (retry scheduled)
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();

    // Verify execution path
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:start');
    expect($events)->toContain('handleApiException:handled');
});
