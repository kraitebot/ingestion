<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Commands\RecoverPositionsCommand;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/**
 * `hasInflightStepFor` must NOT count parked `NotRunnable` rescue steps as an
 * in-flight workflow.
 *
 * NotRunnable is the parked state for `type=resolve-exception` steps — the
 * pre-staged exception handlers (e.g. CancelPositionJob = "cancel if opening
 * fails") that only flip to Pending if a sibling in their block actually
 * fails. On the happy path they stay NotRunnable forever, by design, and the
 * dispatcher already excludes NotRunnable from dispatch.
 *
 * Pre-fix, `kraite:recover-positions` treated those parked rescue steps as a
 * live workflow and deferred forever on any position whose successful opening
 * left a NotRunnable CancelPositionJob behind — i.e. every opened position.
 * (2026-06-09: 5 positions wedged in `syncing` could not be recovered because
 * their inert rescue branch kept the recovery skipping them.)
 */
function invokeHasInflightStepFor(int $positionId): bool
{
    $command = new RecoverPositionsCommand;
    $method = new ReflectionMethod($command, 'hasInflightStepFor');
    $method->setAccessible(true);

    return (bool) $method->invoke($command, $positionId);
}

function insertStepForPosition(string $state, int $positionId, ?string $prefix = null): void
{
    $insert = static fn (): bool => Step::query()->insert([
        'class' => 'Kraite\\Core\\Jobs\\Atomic\\Position\\CancelPositionJob',
        'type' => 'resolve-exception',
        'queue' => 'positions',
        'state' => $state,
        'arguments' => json_encode(['positionId' => $positionId]),
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $prefix === null ? $insert() : Steps::usingPrefix($prefix, $insert);
}

it('does not treat a parked NotRunnable rescue step as in-flight (default prefix)', function (): void {
    insertStepForPosition(NotRunnable::class, 777001);

    expect(invokeHasInflightStepFor(777001))->toBeFalse();
});

it('does not treat a parked NotRunnable rescue step as in-flight (trading prefix)', function (): void {
    insertStepForPosition(NotRunnable::class, 777002, 'trading');

    expect(invokeHasInflightStepFor(777002))->toBeFalse();
});

it('still treats a genuinely in-flight step as in-flight (Pending default, Dispatched trading)', function (): void {
    insertStepForPosition(Pending::class, 777003);
    expect(invokeHasInflightStepFor(777003))->toBeTrue();

    insertStepForPosition(Dispatched::class, 777004, 'trading');
    expect(invokeHasInflightStepFor(777004))->toBeTrue();
});
