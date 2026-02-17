<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StepTester;
use Tests\Support\TestQueueableJob;

uses(RefreshDatabase::class)->group('unit', 'base-queueable-job');

it('Cleans laravel.log', function () {
    file_put_contents(storage_path('logs/laravel.log'), '');

    expect(true)->toBe(true);
});

// Test retryException hook
it('retries when retryException returns true', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'throw_exception' => true,
            'exception_message' => 'Test exception',
            'retry_exception' => true,
        ]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'], // Retried after exception
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('retry_exception_hook')
        ->test();

    // Verify retries was incremented
    $step->refresh();
    expect($step->retries)->toBe(1);
});

// Test ignoreException hook
it('completes when ignoreException returns true', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'throw_exception' => true,
            'exception_message' => 'Test exception',
            'ignore_exception' => true,
        ]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'], // Completed despite exception
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('ignore_exception_hook')
        ->test();
});

// Test resolveException hook
it('resolves exception with custom logic', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'throw_exception' => true,
            'exception_message' => 'Test exception',
            'resolve_exception' => true,
        ]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'], // Resolved to completed
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_hook')
        ->test();

    // Verify response was marked as resolved
    $step->refresh();
    expect($step->response)->toBeArray();
    expect($step->response['resolved'] ?? false)->toBeTrue();
});
