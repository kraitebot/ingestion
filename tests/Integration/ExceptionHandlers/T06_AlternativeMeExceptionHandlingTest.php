<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestAlternativeMeApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'alternativeme');

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

describe('AlternativeMeExceptionHandler - Rate Limits', function () {
    it('handles 429 rate limit by setting dispatch_after', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 429
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeRateLimited',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_429_rate_limit')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
        expect($step->dispatch_after->isFuture())->toBeTrue();
    });
});

describe('AlternativeMeExceptionHandler - Ignorable Errors', function () {
    it('handles 400 bad request as ignorable (skipped)', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 400
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeIgnorableBadRequest',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'completed'],
            ])
            ->withLabel('alternativeme_400_ignorable')
            ->test();

        // Assert: Step is completed (ignorable errors complete the step)
        $step->refresh();
        expect($step->state->value())->toBe('completed');
    });
});

describe('AlternativeMeExceptionHandler - Retryable Errors', function () {
    it('handles 408 request timeout as retryable', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 408
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeRequestTimeout',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_408_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });

    it('handles 500 internal server error as retryable', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 500
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeServerError',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_500_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });

    it('handles 502 bad gateway as retryable', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 502
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeBadGateway',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_502_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });

    it('handles 503 service unavailable as retryable', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 503
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeServiceUnavailable',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_503_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });

    it('handles 504 gateway timeout as retryable', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 504
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeGatewayTimeout',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_504_retryable')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });
});

describe('AlternativeMeExceptionHandler - Forbidden Errors (Account Blocked)', function () {
    it('handles 401 unauthorized by creating forbidden_hostname with type account_blocked', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 401
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeAccountBlocked',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_401_account_blocked')
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

    it('handles 402 payment required by creating forbidden_hostname with type account_blocked', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 402
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemePaymentRequired',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_402_account_blocked')
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

    it('handles 403 forbidden by creating forbidden_hostname with type account_blocked', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
        ]);

        // Create step that throws 403
        $step = StepTester::createSteps([
            ['arguments' => [
                'accountId' => $account->id,
                'throw_exception_stub' => 'alternativemeForbidden',
            ]],
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_403_account_blocked')
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

describe('AlternativeMeExceptionHandler - Network Errors', function () {
    it('handles network connection errors as retryable', function () {
        // Arrange: Create AlternativeMe account
        $apiSystem = ApiSystem::factory()->create(['canonical' => 'alternativeme']);
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
        ], TestAlternativeMeApiableJob::class)[0];

        // Act: Dispatch step
        StepTester::withSteps([$step])
            ->withStatusMatrix([
                1 => [$step->id => 'pending'],
            ])
            ->withLabel('alternativeme_connect_exception')
            ->test();

        // Assert: Step is pending with dispatch_after set
        $step->refresh();
        expect($step->state->value())->toBe('pending');
        expect($step->dispatch_after)->not->toBeNull();
    });
});
