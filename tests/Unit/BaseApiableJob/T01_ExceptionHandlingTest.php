<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestApiableJob;

uses(RefreshDatabase::class)->group('unit', 'base-apiable-job');

it('cleans laravel.log', function () {
    file_put_contents(storage_path('logs/laravel.log'), '');
    expect(true)->toBe(true);
});

it('completes successfully when no exceptions are thrown', function () {
    // Create test account
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'test']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    // Create step with no exceptions
    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'custom_result' => ['success' => true, 'data' => 'test'],
        ]],
    ], TestApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->test();

    $step->refresh();

    // Assert: Job completed successfully
    expect($step->state->value())->toBe('completed');

    // Assert: Execution path tracked correctly
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('assignExceptionHandler');
    expect($events)->toContain('computeApiable:start');
    expect($events)->toContain('computeApiable:success');
});

it('completes successfully when exception handler ignores exception', function () {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'test']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_request_exception' => true,
            'http_status' => 400,
            'response_body' => ['error' => 'Invalid symbol'],
            'handler_ignore_exception' => true, // Handler says: ignore this
        ]],
    ], TestApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->test();

    $step->refresh();

    // Outcome: Job completed successfully
    expect($step->state->value())->toBe('completed');

    // Execution path: Verify exception was caught but not re-thrown
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('computeApiable:throwing_request_exception');
    expect($events)->toContain('handleApiException:start');
    expect($events)->toContain('handleApiException:handled'); // Handled, not rethrown
    expect($events)->not->toContain('handleApiException:rethrow');
});

it('completes when job-level ignoreException returns true', function () {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'test']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception' => true, // Generic exception (not API-specific)
            'exception_message' => 'Non-API error',
            'job_ignore_exception' => true, // Job hook says: ignore
        ]],
    ], TestApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->test();

    $step->refresh();

    expect($step->state->value())->toBe('completed');

    // Verify ignoreException was called
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('ignoreException:called');

    // Check the tracked data
    $ignoreCall = collect($step->response['execution_path'])
        ->firstWhere('event', 'ignoreException:called');
    expect($ignoreCall['data']['result'])->toBeTrue();
});

it('resolves step when resolveException is triggered', function () {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'test']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception' => true,
            'exception_message' => 'Resolvable error',
            'job_resolve_exception' => true, // Resolve this exception
        ]],
    ], TestApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'completed'],
        ])
        ->test();

    $step->refresh();

    expect($step->state->value())->toBe('completed');
    expect($step->response['resolved'])->toBeTrue();

    // Verify execution path
    $events = array_column($step->response['execution_path'], 'event');
    expect($events)->toContain('resolveException:called');
    expect($events)->toContain('resolveException:resolving');
});
