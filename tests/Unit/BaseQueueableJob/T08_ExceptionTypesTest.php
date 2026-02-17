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

// Test generic exception
it('fails when generic exception is thrown', function () {
    $step = StepTester::createSteps([
        ['arguments' => [
            'throw_exception' => true,
            'exception_message' => 'Generic test exception',
        ]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('generic_exception')
        ->test();

    // Verify error message was stored
    $step->refresh();
    expect($step->error_message)->toContain('Generic test exception');
});

// Test NonNotifiableException
it('fails silently when NonNotifiableException is thrown', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['throw_non_notifiable' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('non_notifiable_exception')
        ->test();

    // Verify error message was stored
    $step->refresh();
    expect($step->error_message)->toContain('Non-notifiable');
});

// Test MaxRetriesReachedException
it('fails when MaxRetriesReachedException is thrown', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['throw_max_retries' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('max_retries_exception')
        ->test();

    // Verify error message was stored
    $step->refresh();
    expect($step->error_message)->toContain('Max retries');
});

// Test JustResolveException
it('fails when JustResolveException is thrown without resolver', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['throw_just_resolve' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('just_resolve_exception')
        ->test();
});

// Test JustEndException
it('fails when JustEndException is thrown without resolver', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['throw_just_end' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('just_end_exception')
        ->test();
});
