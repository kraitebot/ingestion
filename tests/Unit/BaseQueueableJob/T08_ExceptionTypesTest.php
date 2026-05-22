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

it('fails when generic exception is thrown', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => [
            'throw_exception' => true,
            'exception_message' => 'Generic test exception',
        ]],
    ], TestQueueableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'failed']])
        ->withLabel('generic_exception')
        ->test();

    $step->refresh();
    expect($step->error_message)->toContain('Generic test exception');
});

it('fails silently when NonNotifiableException is thrown', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['throw_non_notifiable' => true]],
    ], TestQueueableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'failed']])
        ->withLabel('non_notifiable_exception')
        ->test();

    $step->refresh();
    expect($step->error_message)->toContain('Non-notifiable');
});

it('fails when MaxRetriesReachedException is thrown', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['throw_max_retries' => true]],
    ], TestQueueableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'failed']])
        ->withLabel('max_retries_exception')
        ->test();

    $step->refresh();
    expect($step->error_message)->toContain('Max retries');
});
