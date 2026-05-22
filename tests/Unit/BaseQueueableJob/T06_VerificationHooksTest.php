<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StepTester;
use Tests\Support\TestQueueableJob;

uses(RefreshDatabase::class)->group('unit', 'base-queueable-job');

it('Cleans laravel.log', function (): void {
    file_put_contents(storage_path('logs/laravel.log'), '');

    expect(true)->toBe(true);
});

// Test doubleCheck hook
it('retries when doubleCheck returns false', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['double_check' => false]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'], // Retried for double-check
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('double_check_hook')
        ->test();

    // Verify double_check counter was incremented
    $step->refresh();
    expect($step->double_check)->toBe(1);
});

// Test confirmOrRetry hook
it('retries when confirmOrRetry returns false', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['confirm_or_retry' => false]],
    ], TestQueueableJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'], // Retried for confirmation
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('confirm_or_retry_hook')
        ->test();

    // Verify execution_mode was set to confirming-completion
    $step->refresh();
    expect($step->execution_mode)->toBe('confirming-completion');
});
