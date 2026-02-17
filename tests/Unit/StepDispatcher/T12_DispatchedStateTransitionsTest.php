<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StepTester;
use Tests\Support\TestQueueableJob;

uses(RefreshDatabase::class)->group('unit', 'step-dispatcher');

it('Cleans laravel.log', function () {
    file_put_contents(storage_path('logs/laravel.log'), '');

    expect(true)->toBe(true);
});

// Schematic: Pending → Dispatched → Running/Completed
// Normal step should transition through Dispatched state
it('transitions through dispatched state for normal step', function () {
    $step = StepTester::createSteps([[]], TestQueueableJob::class)[0];

    expect($step->state->value())->toBe('pending');

    // First tick: Pending → Dispatched
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    // After dispatch, step should have executed and completed (sync queue)
    expect($step->state->value())->toBeIn(['dispatched', 'completed']);
});

// Schematic: Lifecycle step Pending → Dispatched → Running
// Parent lifecycle step should become Running when dispatched
it('transitions lifecycle step to running when dispatched', function () {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], TestQueueableJob::class)[0];

    expect($parent->state->value())->toBe('pending');

    // Dispatch - parent should become running
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    expect($parent->state->value())->toBe('running');
});

// Schematic: Multiple steps dispatched in same tick
// All ready steps should transition to Dispatched simultaneously
it('dispatches multiple ready steps in same tick', function () {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Parallel
        ['block_uuid' => $block, 'index' => 1], // Parallel
        ['block_uuid' => $block, 'index' => 1], // Parallel
    ], TestQueueableJob::class);

    // All should be pending
    foreach ($steps as $step) {
        expect($step->state->value())->toBe('pending');
    }

    // Single dispatch should handle all
    StepDispatcher\Support\StepDispatcher::dispatch($steps[0]->group);

    // All should have completed (sync queue processes immediately)
    foreach ($steps as $step) {
        $step->refresh();
        expect($step->state->value())->toBe('completed');
    }
});

// Schematic: Sequential dispatch respects index order
// Index 2 should not dispatch until index 1 completes
it('respects index order through dispatched state', function () {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], TestQueueableJob::class);

    [$s1, $s2] = $steps;

    // First tick - only index 1 should dispatch
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    $s2->refresh();

    expect($s1->state->value())->toBe('completed');
    expect($s2->state->value())->toBe('pending');

    // Second tick - index 2 can now dispatch
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    $s2->refresh();

    expect($s1->state->value())->toBe('completed');
    expect($s2->state->value())->toBe('completed');
});

// Schematic: Failed dispatch transitions to Failed
// If step dispatch fails, should transition to Failed
it('transitions to failed when dispatch execution fails', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['fail' => true]],
    ], TestQueueableJob::class)[0];

    expect($step->state->value())->toBe('pending');

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

// Schematic: Stopped dispatch transitions to Stopped
// If step stops execution, should transition to Stopped
it('transitions to stopped when dispatch execution stops', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['stop' => true]],
    ], TestQueueableJob::class)[0];

    expect($step->state->value())->toBe('pending');

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('stopped');
});

// Schematic: Skipped step never reaches Dispatched
// Skipped steps should not be dispatched
it('does not dispatch skipped steps', function () {
    $step = StepTester::createSteps([
        ['arguments' => ['skip' => true]],
    ], TestQueueableJob::class)[0];

    // Manually skip the step
    $step->update(['state' => StepDispatcher\States\Skipped::class]);

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('skipped'); // Still skipped
});

// Schematic: Cancelled step never reaches Dispatched
// Cancelled steps should not be dispatched
it('does not dispatch cancelled steps', function () {
    $step = StepTester::createSteps([[]], TestQueueableJob::class)[0];

    // Manually cancel the step
    $step->update(['state' => StepDispatcher\States\Cancelled::class]);

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('cancelled'); // Still cancelled
});

// Schematic: Completed step not re-dispatched
// Already completed steps should not transition through Dispatched again
it('does not re-dispatch completed steps', function () {
    $step = StepTester::createSteps([[]], TestQueueableJob::class)[0];

    // Complete the step
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Dispatch again - should remain completed
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');
});

// Schematic: Parent dispatched → running, child still pending
// Parent should reach running before children are ready
it('parent becomes running before children dispatch', function () {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], TestQueueableJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
    ], TestQueueableJob::class)[0];

    // First tick - parent dispatches and becomes running
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    $child->refresh();

    expect($parent->state->value())->toBe('running');
    expect($child->state->value())->toBe('pending'); // Child not yet ready

    // Second tick - child can now dispatch
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    $child->refresh();

    expect($parent->state->value())->toBe('running');
    expect($child->state->value())->toBe('completed');
});
