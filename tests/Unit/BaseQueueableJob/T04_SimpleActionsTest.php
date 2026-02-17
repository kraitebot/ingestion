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

// Test stop action
it('stops when stopJob is called', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['stop' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'stopped'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('stop_action')
        ->test();
});

// Test skip action
it('skips when skipJob is called', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['skip' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'skipped'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_action')
        ->test();
});

// Test fail action
it('fails when transitioning to Failed state', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['fail' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('fail_action')
        ->test();
});
