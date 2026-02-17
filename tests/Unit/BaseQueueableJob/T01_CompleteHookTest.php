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

// Test that a step automatically completes after successful execution
it('automatically completes after successful execution', function () {
    $step = StepTester::createSteps([
        [], // No special arguments - just a basic step
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('automatic_completion')
        ->test();
});
