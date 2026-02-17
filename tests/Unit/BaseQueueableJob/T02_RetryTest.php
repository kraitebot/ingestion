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

// Test that a step retries when retryJob() is called
it('transitions to pending when retryJob is called', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['retry' => true]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'], // After retry, back to pending
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('retry_test')
        ->test();
});
