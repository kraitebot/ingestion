<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Jobs\Atomic\System\VerifyDispatcherGroupDrainedJob;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;

/**
 * When a dispatcher group stalls (group_no_progress), a non-critical
 * follow-up is scheduled +15min out. It re-checks whether the group
 * drained and tells the admin the outcome (Info = recovered, High = still
 * stalled).
 */
it('schedules a +15min drain recheck step when a group stalls', function (): void {
    NotificationFacade::fake();

    event(new StaleStepsDetected(
        severity: 'critical',
        reason: 'group_no_progress',
        count: 3,
        context: ['group' => 'alpha', 'pending_count' => 3, 'hostname' => 'tyche'],
    ));

    $step = Step::query()->where('class', VerifyDispatcherGroupDrainedJob::class)->first();

    expect($step)->not->toBeNull();
    expect($step->arguments['group'])->toBe('alpha');
    expect($step->arguments['pendingAtAlert'])->toBe(3);
    expect($step->dispatch_after->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();
});

it('reports drained (Info) when the group is empty at recheck', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => true]);

    $result = (new VerifyDispatcherGroupDrainedJob('alpha', '', 3))->compute();

    expect($result['drained'])->toBeTrue();
    expect($result['pending_now'])->toBe(0);

    NotificationFacade::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => $n->canonical === 'dispatcher_group_drain_recheck' && $n->severity === NotificationSeverity::Info,
    );
});

it('reports still-stalled (High) when the group still has pending steps', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => true]);
    Step::create(['class' => VerifyDispatcherGroupDrainedJob::class, 'group' => 'alpha', 'queue' => 'cronjobs']);

    $result = (new VerifyDispatcherGroupDrainedJob('alpha', '', 3))->compute();

    expect($result['drained'])->toBeFalse();
    expect($result['pending_now'])->toBeGreaterThan(0);

    NotificationFacade::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => $n->canonical === 'dispatcher_group_drain_recheck' && $n->severity === NotificationSeverity::High,
    );
});
