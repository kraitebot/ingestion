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

// Test startOrStop hook
it('stops when startOrStop returns false', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['should_start_or_stop' => false]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'stopped'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('start_or_stop_hook')
        ->test();
});

// Test startOrSkip hook
it('skips when startOrSkip returns false', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['should_start_or_skip' => false]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'skipped'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('start_or_skip_hook')
        ->test();
});

// Test startOrFail hook
it('fails when startOrFail returns false', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['should_start_or_fail' => false]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('start_or_fail_hook')
        ->test();
});

// Test startOrRetry hook
it('retries when startOrRetry returns false', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['should_start_or_retry' => false]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('start_or_retry_hook')
        ->test();

    // Verify retries was incremented
    $step->refresh();
    expect($step->retries)->toBe(1);
});
