<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kraite\Core\Listeners\SendStaleStepsNotification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationModel;
use Kraite\Core\Notifications\AlertNotification;
use StepDispatcher\Events\StaleStepsDetected;

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);

    Kraite::updateOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'test_key',
            'admin_pushover_application_key' => 'test_app_key',
            'notification_channels' => ['mail'],
            'allow_opening_positions' => true,
        ]
    );

    // The seed-migration already wrote the canonical row in production; in
    // tests RefreshDatabase replays migrations so the row is present too.
    // Use firstOrCreate so the test still works on environments where the
    // migration is not yet applied (older branches, ad-hoc test DBs).
    NotificationModel::firstOrCreate(
        ['canonical' => 'group_no_progress_detected'],
        NotificationModel::factory()->groupNoProgress()->raw()
    );
});

/**
 * `SendStaleStepsNotification` listener — `group_no_progress` mapping.
 *
 * The 2026-04-25 wedge proved the per-step stuck-detector is necessary
 * but not sufficient: cleanup-phase return-true bugs blocked dispatch
 * for 16h with no individual step stuck long enough to trip the
 * existing alarm. The new `group_no_progress` reason on
 * `StaleStepsDetected` (fired by `RecoverStaleStepsCommand
 * --watchdog-progress`) generalises stall detection — Pending count > 0
 * AND no terminal-state progress in N minutes = wedge of unknown shape.
 *
 * The package fires the event semantically; the Kraite-side listener
 * routes it to a Pushover canonical so the operator gets paged. Without
 * this mapping the event lands silently and the very wedge class the
 * watchdog was built to catch goes back to costing 16h of operational
 * blindness.
 */
it('routes a group_no_progress StaleStepsDetected event to the group_no_progress_detected pushover canonical', function (): void {
    LaravelNotification::fake();

    $listener = new SendStaleStepsNotification;

    $listener->handle(new StaleStepsDetected(
        severity: 'critical',
        reason: 'group_no_progress',
        count: 27,
        context: [
            'group' => 'eta',
            'pending_count' => 27,
            'last_terminal_update' => '2026-04-25 17:55:00',
            'progress_threshold_seconds' => 600,
            'hostname' => 'production-vps',
        ],
    ));

    LaravelNotification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function (AlertNotification $notification): bool {
            return $notification->canonical === 'group_no_progress_detected';
        }
    );
});

it('does not route a group_no_progress event when notifications are globally disabled', function (): void {
    config(['kraite.notifications_enabled' => false]);
    LaravelNotification::fake();

    $listener = new SendStaleStepsNotification;

    $listener->handle(new StaleStepsDetected(
        severity: 'critical',
        reason: 'group_no_progress',
        count: 27,
        context: ['group' => 'eta', 'pending_count' => 27],
    ));

    LaravelNotification::assertNothingSent();
});

it('does not route an unrelated reason to the group canonical', function (): void {
    LaravelNotification::fake();

    $listener = new SendStaleStepsNotification;

    // A reason the listener should NOT map to group_no_progress_detected.
    $listener->handle(new StaleStepsDetected(
        severity: 'warning',
        reason: 'stale_running_steps_recovered',
        count: 1,
    ));

    LaravelNotification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function (AlertNotification $notification): bool {
            return $notification->canonical === 'group_no_progress_detected';
        }
    );
});
