<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestBybitApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'bybit');

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

it('handles 403 rate limit by setting dispatch_after', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 403 exception (IP rate limit breached)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitIpRateLimitedHttp403',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step (rate limit will be triggered)
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step should be pending after rate limit
        ])
        ->withLabel('bybit_403_rate_limit')
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

it('handles 429 system-level rate limit by setting dispatch_after', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 429 (System level frequency protection)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitIpBannedHttp429',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_429_rate_limit')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('handles 200/10006 rate limit by setting dispatch_after', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200 with retCode 10006 (Too many visits)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitRateLimitedPerUid',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_200_10006_rate_limit')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('handles 200/10018 exceeded IP rate limit by creating forbidden_hostname with forbidden_until', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200 with retCode 10018 (Exceeded IP rate limit)
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
        ->withLabel('bybit_200_10018_rate_limit')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();

    // Assert: ForbiddenHostname entry created with type ip_rate_limited
    $forbiddenHostname = ForbiddenHostname::where('api_system_id', $apiSystem->id)
        ->first();

    expect($forbiddenHostname)->not->toBeNull();
    expect($forbiddenHostname->type)->toBe(ForbiddenHostname::TYPE_IP_RATE_LIMITED);
    expect($forbiddenHostname->account_id)->toBeNull(); // System-wide
    expect($forbiddenHostname->forbidden_until)->not->toBeNull(); // Has expiry
});

it('handles 200/10010 IP not whitelisted by creating account-specific forbidden_hostname', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/10010 (IP not whitelisted)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitIpNotWhitelisted',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_200_10010_ip_not_whitelisted')
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

it('handles 200/10009 IP banned by creating forbidden_hostname', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/10009 (IP has been banned)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitIpBanned',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_200_10009_ip_banned')
        ->test();

    // Assert: Step is pending
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

it('handles 401 authentication failure by creating forbidden_hostname', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 401 (Authentication failed)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitAccountBlockedHttp401',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_401_forbidden')
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
    expect($forbiddenHostname->account_id)->toBe($account->id);
});

it('handles 200/10003 account blocked by creating forbidden_hostname', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/10003 (API key invalid)
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
        ->withLabel('bybit_200_10003_account_blocked')
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
    expect($forbiddenHostname->account_id)->toBe($account->id);
});

it('ignores 200/34040 exception and completes step', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/34040 (Already set this TP/SL value)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitIgnorableTpSlSet',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'], // Exception ignored, step completes
        ])
        ->withLabel('bybit_200_34040_ignorable')
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

it('ignores 200/110025 exception and completes step', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/110025 (Position mode not modified)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitIgnorablePositionMode',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->withLabel('bybit_200_110025_ignorable')
        ->test();

    // Assert: Step completed successfully
    $step->refresh();
    expect($step->state->value())->toBe('completed');
});

it('retries 503 exception with backoff in dispatch_after', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 503 (Service Unavailable)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitServiceUnavailable',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Exception rethrown, BaseQueueableJob retries
        ])
        ->withLabel('bybit_503_retryable')
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

it('retries 200/10019 exception with backoff in dispatch_after', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/10019 (Service restarting)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitServiceRestarting',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_200_10019_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 200/170007 backend timeout exception with backoff', function () {
    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/170007 (Backend timeout)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitBackendTimeout',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bybit_200_170007_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('handles 200/10002 recvWindow mismatch by retrying with backoff', function () {
    // Fake notifications to prevent actual sending
    Illuminate\Support\Facades\Notification::fake();

    // Note: Engine admin is created in beforeEach, no need to recreate

    // Arrange: Create Bybit account
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'bybit',
        'recvwindow_margin' => 5000, // Initial value
    ]);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 200/10002 (Invalid timestamp/recv_window)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bybitRecvWindowMismatch',
        ]],
    ], TestBybitApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step retries after handling recvWindow
        ])
        ->withLabel('bybit_200_10002_recvwindow')
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
