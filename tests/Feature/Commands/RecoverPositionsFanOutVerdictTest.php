<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Support\Recovery\RecoveryReport;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\Steps;

/**
 * Pins the fan-out success/failure verdict for kraite:recover-positions.
 *
 * The fan-out path dispatches one per-account job, polls until every step
 * reaches a TERMINAL state, then aggregates. `terminalStepStates()` includes
 * Failed + Stopped — so "all settled" is NOT "all succeeded". Pre-fix,
 * aggregateFanOutResults read a Failed step's exception payload as a
 * zero-count success: failed accounts were invisible, the command returned
 * SUCCESS, trading was un-frozen, and a "recovery completed" notification
 * fired over a database that was never rebuilt. This locks the verdict:
 * a failed terminal state must be counted as a failure and surfaced.
 */
function makeFanOutStep(string $workflowId, string $state, ?int $accountId = null, ?array $payload = null): Step
{
    return Steps::usingPrefix('trading', function () use ($workflowId, $state, $accountId, $payload): Step {
        $step = Step::create([
            'class' => 'Kraite\\Core\\Jobs\\Recovery\\RecoverAccountPositionsJob',
            'queue' => 'positions',
            'relatable_type' => 'Kraite\\Core\\Models\\Account',
            'relatable_id' => $accountId ?? random_int(1000, 9999),
            'arguments' => ['accountId' => $accountId ?? 1],
            'workflow_id' => $workflowId,
            'index' => 1,
        ]);

        // Force the terminal/non-default state directly and attach the
        // stored return value the aggregator decodes.
        Step::query()->whereKey($step->id)->update([
            'state' => $state,
            'response' => $payload !== null ? json_encode($payload) : null,
        ]);

        return $step->fresh();
    });
}

function invokeAggregate(string $workflowId, RecoveryReport $report): int
{
    $command = new RecoverPositionsCommand;
    $method = new ReflectionMethod($command, 'aggregateFanOutResults');

    return (int) $method->invoke($command, $workflowId, $report);
}

function invokeFanOutStepFailed(Step $step): bool
{
    $command = new RecoverPositionsCommand;
    $method = new ReflectionMethod($command, 'fanOutStepFailed');

    return (bool) $method->invoke($command, $step);
}

it('counts a Failed step as a failure and never reads its payload as success', function (): void {
    $workflowId = (string) Str::uuid();

    // One clean account (2 positions rebuilt) + one account whose job died.
    makeFanOutStep($workflowId, Completed::class, accountId: 501, payload: [
        'accounts_ok' => 1,
        'positions_created' => 2,
        'orders_created' => 5,
    ]);
    // A Failed step still carries a response — an exception dump. It must
    // NOT be read as accounts_ok/positions_created data.
    makeFanOutStep($workflowId, Failed::class, accountId: 502, payload: [
        'exception' => 'RuntimeException: exchange 401 during recovery',
        'accounts_ok' => 1,
    ]);

    $report = new RecoveryReport;
    $failures = invokeAggregate($workflowId, $report);

    // Verdict: exactly one failure — this is what flips runFanOut to FAILURE.
    expect($failures)->toBe(1);

    // Both accounts were inspected.
    expect($report->accountsChecked)->toBe(2);

    // Only the genuinely-completed account contributes success metrics.
    expect($report->accountsOk)->toBe(1);
    expect($report->positionsCreated)->toBe(2);
    expect($report->ordersCreated)->toBe(5);

    // The failed account is surfaced (skipped + warned), never silent.
    expect($report->accountsSkipped)->toBeGreaterThanOrEqual(1);
    expect(collect($report->warnings)->contains(fn (string $w): bool => str_contains($w, '502')))->toBeTrue();
});

it('returns zero failures when every account job completed', function (): void {
    $workflowId = (string) Str::uuid();

    makeFanOutStep($workflowId, Completed::class, accountId: 511, payload: ['accounts_ok' => 1, 'positions_created' => 3]);
    makeFanOutStep($workflowId, Completed::class, accountId: 512, payload: ['accounts_ok' => 1, 'positions_created' => 1]);

    $report = new RecoveryReport;
    $failures = invokeAggregate($workflowId, $report);

    expect($failures)->toBe(0);
    expect($report->accountsOk)->toBe(2);
    expect($report->positionsCreated)->toBe(4);
    expect($report->accountsChecked)->toBe(2);
});

it('counts Stopped and Cancelled terminal states as failures too', function (): void {
    $workflowId = (string) Str::uuid();

    makeFanOutStep($workflowId, Stopped::class, accountId: 521);
    makeFanOutStep($workflowId, Cancelled::class, accountId: 522);
    makeFanOutStep($workflowId, Completed::class, accountId: 523, payload: ['accounts_ok' => 1]);

    $report = new RecoveryReport;
    $failures = invokeAggregate($workflowId, $report);

    expect($failures)->toBe(2);
    expect($report->accountsOk)->toBe(1);
});

it('a Skipped step is intentional — not a failure', function (): void {
    // startOrSkip=false (e.g. untested-exchange gate, health-check skip) is a
    // deliberate no-op, not a crash. It must not freeze trading.
    $workflowId = (string) Str::uuid();

    makeFanOutStep($workflowId, Skipped::class, accountId: 531);
    makeFanOutStep($workflowId, Completed::class, accountId: 532, payload: ['accounts_ok' => 1]);

    $report = new RecoveryReport;
    $failures = invokeAggregate($workflowId, $report);

    expect($failures)->toBe(0);
});

it('renders the state name in the failure warning without throwing (state is an object)', function (): void {
    // Regression for `class_basename((string) $step->state)`: the state is a
    // Spatie state OBJECT, and casting it to string is not guaranteed. The
    // aggregator must format it via class_basename() directly.
    $workflowId = (string) Str::uuid();
    $failed = makeFanOutStep($workflowId, Failed::class, accountId: 541);

    $report = new RecoveryReport;
    invokeAggregate($workflowId, $report);

    expect(collect($report->warnings)->contains(fn (string $w): bool => str_contains($w, 'Failed')))->toBeTrue();

    // And the predicate itself is correct across states.
    expect(invokeFanOutStepFailed($failed))->toBeTrue();
});

it('fanOutStepFailed is false for a still-pending step', function (): void {
    $workflowId = (string) Str::uuid();
    $pending = makeFanOutStep($workflowId, Pending::class, accountId: 551);

    expect(invokeFanOutStepFailed($pending))->toBeFalse();
});
