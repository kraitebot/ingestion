<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob;
use Kraite\Core\Models\Position;

/**
 * Pin the generic position-status updater. Every close / cancel / WAP /
 * sync workflow funnels through this job to flip the parent Position
 * row, so the truth-table here is load-bearing for the entire lifecycle:
 * a missed transition silently leaves a position in a stuck state, and
 * the conditional `onlyFromStatus` guard is what keeps concurrent
 * workflows (e.g. WAP revert-to-active racing a close-flow flip-to-
 * closing) from clobbering each other's state.
 *
 * Tests cover three concerns:
 *
 *  1. Each public status string maps to its correct `updateTo*()` call.
 *  2. The `onlyFromStatus` guard fires the transition only when the
 *     current status matches — string variant + array variant.
 *  3. Unknown statuses surface as a RuntimeException so a typo in a
 *     downstream caller fails loudly rather than silently no-oping.
 */
function newPositionForStatusJob(string $startingStatus = 'active'): Position
{
    return Position::factory()->long()->create(['status' => $startingStatus]);
}

function runUpdatePositionStatus(Position $position, string $status, ?string $message = null, string|array|null $onlyFromStatus = null): array
{
    $job = new UpdatePositionStatusJob(
        positionId: $position->id,
        status: $status,
        message: $message,
        onlyFromStatus: $onlyFromStatus,
    );

    return $job->compute();
}

// ───────────────────────── status mapping ─────────────────────────

it('flips a position to cancelling', function (): void {
    $position = newPositionForStatusJob('opening');
    runUpdatePositionStatus($position, 'cancelling');
    expect($position->fresh()->status)->toBe('cancelling');
});

it('flips a position to closing', function (): void {
    $position = newPositionForStatusJob('active');
    runUpdatePositionStatus($position, 'closing');
    expect($position->fresh()->status)->toBe('closing');
});

it('flips a position to syncing', function (): void {
    $position = newPositionForStatusJob('active');
    runUpdatePositionStatus($position, 'syncing');
    expect($position->fresh()->status)->toBe('syncing');
});

it('flips a position to closed and stamps closed_at', function (): void {
    $position = newPositionForStatusJob('closing');
    runUpdatePositionStatus($position, 'closed');

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull();
});

it('flips a position to cancelled with an error message', function (): void {
    $position = newPositionForStatusJob('cancelling');
    $position->update(['opened_at' => now()->subMinutes(5)]);

    runUpdatePositionStatus($position, 'cancelled', 'cancel-flow ran');

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('cancelled')
        ->and($fresh->error_message)->toBe('cancel-flow ran')
        ->and($fresh->closed_at)->not->toBeNull();
});

it('flips a position to failed and applies the failure message', function (): void {
    $position = newPositionForStatusJob('opening');
    runUpdatePositionStatus($position, 'failed', 'rejected by exchange');

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->error_message)->toBe('rejected by exchange');
});

it('flips a position to active', function (): void {
    $position = newPositionForStatusJob('syncing');
    runUpdatePositionStatus($position, 'active');
    expect($position->fresh()->status)->toBe('active');
});

it('flips a position to watching', function (): void {
    $position = newPositionForStatusJob('active');
    runUpdatePositionStatus($position, 'watching');
    expect($position->fresh()->status)->toBe('watching');
});

it('flips a position to waping', function (): void {
    $position = newPositionForStatusJob('active');
    runUpdatePositionStatus($position, 'waping');
    expect($position->fresh()->status)->toBe('waping');
});

// ───────────────────────── onlyFromStatus guard ─────────────────────────

it('respects the onlyFromStatus string guard when the current status matches', function (): void {
    $position = newPositionForStatusJob('syncing');
    runUpdatePositionStatus($position, 'active', onlyFromStatus: 'syncing');
    expect($position->fresh()->status)->toBe('active');
});

it('skips the transition when the onlyFromStatus string guard mismatches', function (): void {
    // Real-world race: WAP wants to revert to active, but a close-flow
    // already advanced the position to 'closing'. The string guard must
    // refuse to clobber.
    $position = newPositionForStatusJob('closing');

    $result = runUpdatePositionStatus($position, 'active', onlyFromStatus: 'syncing');

    expect($position->fresh()->status)->toBe('closing')
        ->and($result['skipped'] ?? false)->toBeTrue()
        ->and($result['previous_status'])->toBe('closing')
        ->and($result['requested_status'])->toBe('active');
});

it('respects the onlyFromStatus array guard when the current status is in the list', function (): void {
    // Mid-sync ApplyWap path: both 'active' and 'syncing' are valid
    // entry states for the WAP revert-to-active call.
    $position = newPositionForStatusJob('syncing');
    runUpdatePositionStatus($position, 'active', onlyFromStatus: ['active', 'syncing']);
    expect($position->fresh()->status)->toBe('active');
});

it('skips the transition when the onlyFromStatus array guard fully mismatches', function (): void {
    $position = newPositionForStatusJob('closing');
    $result = runUpdatePositionStatus($position, 'active', onlyFromStatus: ['active', 'syncing']);

    expect($position->fresh()->status)->toBe('closing')
        ->and($result['skipped'] ?? false)->toBeTrue();
});

it('records the expected guard expectation in the skip reason for diagnostics', function (): void {
    $position = newPositionForStatusJob('cancelling');
    $result = runUpdatePositionStatus($position, 'active', onlyFromStatus: ['active', 'syncing']);

    expect($result['reason'])->toContain('active')
        ->and($result['reason'])->toContain('syncing')
        ->and($result['reason'])->toContain('cancelling');
});

// ───────────────────────── unknown status ─────────────────────────

it('throws a RuntimeException on an unknown status string (loud failure for downstream typos)', function (): void {
    $position = newPositionForStatusJob('active');
    runUpdatePositionStatus($position, 'gobbledygook');
})->throws(RuntimeException::class, 'Unknown position status: gobbledygook');
