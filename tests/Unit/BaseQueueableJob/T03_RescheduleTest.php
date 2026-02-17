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

// Test that rescheduleWithoutRetry sets dispatch_after and prevents immediate execution
it('reschedules without retry and sets dispatch_after', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'reschedule_without_retry' => true,
            'reschedule_seconds' => 2,
        ]],
    ], TestQueueableJob::class)[0];

    // Tick 1: Step reschedules, sets dispatch_after, transitions to pending
    $statusMatrix = [
        1 => [$step->id => 'pending'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('reschedule_sets_pending')
        ->test();

    // Verify dispatch_after was set and is in the future
    $step->refresh();
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
    expect($step->was_throttled)->toBeTrue();
    expect($step->retries)->toBe(0); // rescheduleWithoutRetry doesn't increment retries

    // Tick 2: Try to dispatch immediately - should still be pending (dispatch_after not passed)
    $statusMatrix2 = [
        1 => [$step->id => 'pending'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix2)
        ->withLabel('respects_dispatch_after_before_time')
        ->test();

    // Verify step is still pending with same dispatch_after
    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after->isFuture())->toBeTrue();
});
