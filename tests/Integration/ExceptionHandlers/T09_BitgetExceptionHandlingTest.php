<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestBitgetApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'bitget');

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
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 429 (Rate limit exceeded)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetIpRateLimited',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step (rate limit will be triggered)
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'], // Step should be pending after rate limit
        ])
        ->withLabel('bitget_429_rate_limit')
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

it('handles 429 rate limit with Retry-After header', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 429 with Retry-After header
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetRateLimitedWithRetryAfter',
            'throw_exception_stub_args' => [15], // 15 seconds retry
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_429_rate_limit_with_retry_after')
        ->test();

    // Assert: Step is pending with dispatch_after set
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('handles 401 authentication failure by creating forbidden_hostname', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 401 (Account blocked)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetAccountBlocked',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_401_forbidden')
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

it('handles 40014 invalid API key by creating forbidden_hostname', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws BitGet 40014 (Invalid API key)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetApiKeyInvalid',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_40014_api_key_invalid')
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

it('handles 40017 not a trader by creating forbidden_hostname', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws BitGet 40017 (Not a trader)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetNotATrader',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_40017_not_a_trader')
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

it('handles 40018 invalid passphrase by creating forbidden_hostname', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws BitGet 40018 (Invalid passphrase)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetInvalidPassphrase',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_40018_invalid_passphrase')
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

it('handles 403 forbidden by failing step', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 403 (Forbidden)
    // Note: 403 in BitGet is in serverForbiddenHttpCodes but not specifically handled,
    // so it causes the step to fail (exception rethrown)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetForbidden',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'], // Step fails due to unhandled 403
        ])
        ->withLabel('bitget_403_forbidden')
        ->test();

    // Assert: Step is failed
    $step->refresh();
    expect($step->state->value())->toBe('failed');

    // Verify execution path shows exception was rethrown
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('handleApiException:start');
    expect($events)->toContain('handleApiException:rethrow');
});

it('retries 45001 system maintenance error with backoff', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws BitGet 45001 (System maintenance - retryable)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetSystemMaintenance',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_45001_system_maintenance')
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

it('retries 408 request timeout exception with backoff', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 408 (Request timeout)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetRequestTimeout',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_408_retryable')
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

it('retries 500 server error exception with backoff', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 500 (Internal Server Error)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetServerError',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_500_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 502 bad gateway exception with backoff', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 502 (Bad Gateway)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetBadGateway',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_502_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 503 service unavailable exception with backoff', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 503 (Service Unavailable)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetServiceUnavailable',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_503_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('retries 504 gateway timeout exception with backoff', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws 504 (Gateway Timeout)
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetGatewayTimeout',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_504_retryable')
        ->test();

    // Assert: Step is pending with backoff
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->retries)->toBe(1);
});

it('fails step for 40808 parameter verification exception', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step that throws BitGet 40808 (Parameter verification exception)
    // This is a business logic error, not retryable
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetParameterVerificationException',
        ]],
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'failed'], // Step fails - not retryable
        ])
        ->withLabel('bitget_40808_parameter_verification')
        ->test();

    // Assert: Step is failed
    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

it('completes step successfully when no exception is thrown', function () {
    // Arrange: Create BitGet account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
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
    ], TestBitgetApiableJob::class)[0];

    // Act: Dispatch step
    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->withLabel('bitget_success')
        ->test();

    // Assert: Step completed successfully
    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Verify execution path shows success
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('computeApiable:success');
    expect($events)->not->toContain('handleApiException:start');
});
