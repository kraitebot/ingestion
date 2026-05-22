<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use StepDispatcher\Models\StepsDispatcher;
use Tests\Support\StepTester;
use Tests\Support\TestQueueableJob;

uses(RefreshDatabase::class)->group('unit', 'step-dispatcher');

it('Cleans laravel.log', function (): void {
    file_put_contents(storage_path('logs/laravel.log'), '');

    expect(true)->toBe(true);
});

// Schematic: Concurrent dispatch prevention
// When dispatcher is already running, second dispatch should be skipped
it('skips dispatch when lock is already held', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], TestQueueableJob::class);

    $group = $steps[0]->group;

    // Manually acquire lock
    expect(StepsDispatcher::startDispatch($group))->toBe(true);

    // Try to dispatch - should skip because lock is held
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    // Step should still be pending (not dispatched)
    $steps[0]->refresh();
    expect($steps[0]->state->value())->toBe('pending');

    // Release lock
    StepsDispatcher::endDispatch(0, $group);

    // Now dispatch should work
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $steps[0]->refresh();
    expect($steps[0]->state->value())->toBe('completed');
});

// Schematic: Lock release on exception
// Even if dispatcher fails, lock should be released
it('releases lock even when dispatcher throws exception', function (): void {
    $block = (string) Str::uuid();

    // Create a step with invalid class that will cause dispatch to fail
    $step = StepDispatcher\Models\Step::factory()->create([
        'block_uuid' => $block,
        'index' => 1,
        'class' => 'NonExistent\\Class\\That\\Will\\Fail',
        'queue' => 'sync',
    ]);

    $group = $step->group;

    // Dispatch - will fail but should release lock
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    // Lock should be released, so we should be able to acquire it
    expect(StepsDispatcher::startDispatch($group))->toBe(true);

    // Clean up
    StepsDispatcher::endDispatch(0, $group);
});

// Schematic: Multiple groups can run simultaneously
// Alpha and beta groups should not block each other
it('allows concurrent dispatch for different groups', function (): void {
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();

    $stepsA = StepTester::createSteps([
        ['block_uuid' => $blockA, 'index' => 1],
    ], TestQueueableJob::class);

    $stepsB = StepTester::createSteps([
        ['block_uuid' => $blockB, 'index' => 1],
    ], TestQueueableJob::class);

    // Force different groups
    $stepsA[0]->update(['group' => 'alpha']);
    $stepsB[0]->update(['group' => 'beta']);

    // Acquire lock for alpha
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Should still be able to acquire lock for beta
    expect(StepsDispatcher::startDispatch('beta'))->toBe(true);

    // Release locks
    StepsDispatcher::endDispatch(0, 'alpha');
    StepsDispatcher::endDispatch(0, 'beta');
});

// Schematic: Lock tracking per group
// Each group maintains its own lock state
it('tracks lock state independently per group', function (): void {
    // Acquire alpha lock
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Cannot acquire alpha lock again
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(false);

    // But can acquire beta lock
    expect(StepsDispatcher::startDispatch('beta'))->toBe(true);

    // Release alpha
    StepsDispatcher::endDispatch(0, 'alpha');

    // Now can acquire alpha again
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Clean up
    StepsDispatcher::endDispatch(0, 'alpha');
    StepsDispatcher::endDispatch(0, 'beta');
});

// Schematic: Null group lock behavior
// Null group (global dispatch) should have its own lock
it('handles null group lock independently', function (): void {
    // Acquire null group lock
    expect(StepsDispatcher::startDispatch(null))->toBe(true);

    // Cannot acquire null group lock again
    expect(StepsDispatcher::startDispatch(null))->toBe(false);

    // But can acquire named group lock
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Release locks
    StepsDispatcher::endDispatch(0, null);
    StepsDispatcher::endDispatch(0, 'alpha');
});

// Schematic: Progress tracking in lock
// endDispatch receives progress parameter indicating how far dispatch got
it('tracks dispatch progress through lock lifecycle', function (): void {
    $block = (string) Str::uuid();

    $step = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], TestQueueableJob::class)[0];

    $group = $step->group;

    // Start dispatch
    expect(StepsDispatcher::startDispatch($group))->toBe(true);

    // Simulate dispatch progression
    StepsDispatcher::endDispatch(3, $group); // Made it to step 3

    // Should be able to start again
    expect(StepsDispatcher::startDispatch($group))->toBe(true);

    StepsDispatcher::endDispatch(7, $group); // Completed full cycle (step 7)
});

// Schematic: Idempotent dispatch
// Running dispatch multiple times for same group should be safe
it('handles idempotent dispatch calls safely', function (): void {
    $block = (string) Str::uuid();

    $step = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], TestQueueableJob::class)[0];

    $group = $step->group;

    // First dispatch - should complete step
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Second dispatch - should be safe (no pending steps)
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $step->refresh();
    expect($step->state->value())->toBe('completed'); // Still completed

    // Third dispatch - still safe
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');
});
