<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob;
use Kraite\Core\Models\Position;

/**
 * Covers the onlyFromStatus guard added to UpdatePositionStatusJob to prevent
 * WAP's status-revert from clobbering state owned by a concurrent close
 * workflow. Every WAP block trailer (step 5 + resolve-exception) now runs
 * through this guard — these tests pin its behaviour in both directions.
 */
function buildPositionInStatus(string $status): Position
{
    return Position::factory()->long()->create(['status' => $status]);
}

function runStatusUpdate(Position $position, string $target, ?string $onlyFromStatus = null): UpdatePositionStatusJob
{
    $job = new UpdatePositionStatusJob(
        positionId: $position->id,
        status: $target,
        message: 'test',
        onlyFromStatus: $onlyFromStatus,
    );

    $job->compute();

    return $job;
}

it('flips status when onlyFromStatus matches current state', function () {
    $position = buildPositionInStatus('waping');

    runStatusUpdate($position, 'active', onlyFromStatus: 'waping');

    expect($position->fresh()->status)->toBe('active');
});

it('no-ops when onlyFromStatus does not match current state', function () {
    $position = buildPositionInStatus('closing');

    runStatusUpdate($position, 'active', onlyFromStatus: 'waping');

    expect($position->fresh()->status)->toBe('closing');
});

it('flips status unconditionally when onlyFromStatus is omitted', function () {
    $position = buildPositionInStatus('closing');

    runStatusUpdate($position, 'active');

    expect($position->fresh()->status)->toBe('active');
});

it('no-ops the WAP resolve-exception path when close has already claimed the position', function () {
    // Real-world race: a TP filled mid-WAP, the observer dispatched a close
    // workflow, and the close has already transitioned the position into
    // 'closing'. WAP's resolve-exception must not drag it back to 'active'.
    $position = buildPositionInStatus('closing');

    runStatusUpdate($position, 'active', onlyFromStatus: 'waping');

    expect($position->fresh()->status)->toBe('closing');
});

it('returns a skipped result payload when the guard blocks the transition', function () {
    $position = buildPositionInStatus('cancelling');

    $job = new UpdatePositionStatusJob(
        positionId: $position->id,
        status: 'active',
        onlyFromStatus: 'waping',
    );

    $result = $job->compute();

    expect($result['skipped'])->toBeTrue()
        ->and($result['previous_status'])->toBe('cancelling')
        ->and($result['requested_status'])->toBe('active');
});
