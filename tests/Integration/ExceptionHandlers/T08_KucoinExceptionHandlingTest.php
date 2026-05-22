<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestKucoinApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'kucoin');

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

it('handles 429 rate limit by setting dispatch_after', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 429 (Rate limit exceeded)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinIpRateLimited',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step (rate limit will be triggered)
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step should be pending after rate limit
        ])
        ->withLabel('kucoin_429_rate_limit')
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

it('handles 429000 KuCoin rate limit code by setting dispatch_after', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin-specific 429000 rate limit code
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinRateLimited429000',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_429000_rate_limit')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();

    // Verify execution path shows handled
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:handled');
});

it('handles 429 rate limit with Retry-After header', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 429 with Retry-After header
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinRateLimitedWithRetryAfter',
            'throw_exception_stub_args' => [15], // 15 seconds retry
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_429_rate_limit_with_retry_after')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('handles 401 authentication failure by creating forbidden_hostname', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 401 (Account blocked)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinAccountBlocked',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_401_forbidden')
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

it('handles 400100 invalid API key by creating forbidden_hostname', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin 400100 (Invalid API key)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinApiKeyInvalid',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_400100_api_key_invalid')
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
});

it('handles 411100 user frozen by creating forbidden_hostname', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin 411100 (User frozen)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinUserFrozen',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_411100_user_frozen')
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
});

it('handles 403 forbidden by failing step', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 403 (Forbidden)
    // Note: 403 in KuCoin is in serverForbiddenHttpCodes but not specifically handled,
    // so it causes the step to fail (exception rethrown)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinForbidden',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'], // Step fails due to unhandled 403
        ])
        ->withLabel('kucoin_403_forbidden')
        ->test();

    // Assert: Step is failed
    $step->refresh();
    expect($step->state->value())->toBe('failed');

    // Verify execution path shows exception was rethrown
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:start');
    expect($events)->toContain('handleApiException:rethrow');
});

it('retries 300000 internal error with backoff', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin 300000 (Internal error - retryable)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinInternalError',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_300000_internal_error')
        ->test();

    // Assert: Step is pending with dispatch_after set (backoff applied)
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);

    // Verify execution path shows exception was rethrown
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:rethrow');
});

it('retries 408 request timeout exception with backoff', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 408 (Request timeout)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinRequestTimeout',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_408_retryable')
        ->test();

    // Assert: Step is pending with dispatch_after set (backoff applied)
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);

    // Verify execution path shows exception was rethrown
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:rethrow');
});

it('retries 500 server error exception with backoff', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 500 (Internal Server Error)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinServerError',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_500_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 502 bad gateway exception with backoff', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 502 (Bad Gateway)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinBadGateway',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_502_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 503 service unavailable exception with backoff', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 503 (Service Unavailable)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinServiceUnavailable',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_503_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 504 gateway timeout exception with backoff', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 504 (Gateway Timeout)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinGatewayTimeout',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('kucoin_504_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries step for 200004 order not exist error (eventual consistency)', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin 200004 (Order not exist)
    // This is retryable due to KuCoin's eventual consistency during high load
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinOrderNotExist',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step retries - eventual consistency
        ])
        ->withLabel('kucoin_200004_order_not_exist')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('fails step for 200003 insufficient balance error', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin 200003 (Insufficient balance)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinInsufficientBalance',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'], // Step fails - not retryable
        ])
        ->withLabel('kucoin_200003_insufficient_balance')
        ->test();

    // Assert: Step is failed
    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

it('fails step for 400001 invalid parameter error', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws KuCoin 400001 (Invalid parameter)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'kucoinInvalidParameter',
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'], // Step fails - not retryable
        ])
        ->withLabel('kucoin_400001_invalid_parameter')
        ->test();

    // Assert: Step is failed
    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

it('completes step successfully when no exception is thrown', function (): void {
    // Arrange: Create KuCoin account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'kucoin']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that doesn't throw any exception
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            // No exception stub - will succeed
        ]],
    ], TestKucoinApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->withLabel('kucoin_success')
        ->test();

    // Assert: Step completed successfully
    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Verify execution path shows success
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('computeApiable:success');
    expect($events)->not->toContain('handleApiException:start');
});
