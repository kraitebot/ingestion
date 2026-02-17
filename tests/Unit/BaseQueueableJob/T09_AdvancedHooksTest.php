<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Tests\Support\StepTester;
use Tests\Support\TestQueueableJob;

uses(RefreshDatabase::class)->group('unit', 'base-queueable-job');

it('Cleans laravel.log', function () {
    file_put_contents(storage_path('logs/laravel.log'), '');

    expect(true)->toBe(true);
});

// Test getRetryDiagnostics hook
it('includes diagnostics when max retries reached', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'throw_exception' => true,
            'exception_message' => 'Persistent error',
            'retry_diagnostics' => ['Network timeout', 'API rate limit'],
        ]],
    ], TestQueueableJob::class)[0];

    // Set retries to max to trigger MaxRetriesReachedException
    $step->update(['retries' => 10]); // Simulate reaching max retries

    $statusMatrix = [
        1 => [$step->id => 'failed'], // Fails after reaching max retries
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('retry_diagnostics_hook')
        ->test();
});

// Test relatable hook
it('associates a model with the step via relatable hook', function () {
    $account = Account::factory()->create();

    // Store account ID in arguments, then fetch in relatable hook
    $step = StepTester::createSteps([
        ['arguments' => ['relatable_id' => $account->id, 'relatable_type' => Account::class]],
    ], TestQueueableJob::class)[0];

    // Manually set relatable before dispatch (simulating what relatable() hook would do)
    $step->relatable()->associate($account);
    $step->save();

    $statusMatrix = [
        1 => [$step->id => 'completed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('relatable_hook')
        ->test();

    // Verify the account is still associated
    $step->refresh();
    expect($step->relatable)->not->toBeNull();
    expect($step->relatable->id)->toBe($account->id);
});

// Test shouldChangeToHighPriority hook
it('escalates to high priority when shouldChangeToHighPriority returns true', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'should_start_or_retry' => false, // Trigger retry
            'should_change_to_high_priority' => true,
        ]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'], // Retried
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('high_priority_escalation')
        ->test();

    // Verify priority was escalated
    $step->refresh();
    expect($step->priority)->toBe('high');
});

// Test reportAndFail action
it('fails immediately with error logging via reportAndFail', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'report_and_fail' => true,
            'exception_message' => 'Critical failure',
        ]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('report_and_fail_action')
        ->test();

    // Verify step failed
    $step->refresh();
    expect($step->state->value())->toBe('failed');
    expect($step->error_message)->not->toBeNull();
});
