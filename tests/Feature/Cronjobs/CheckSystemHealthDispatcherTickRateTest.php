<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Health\SystemHealthCheckType;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Pending;

beforeEach(function (): void {
    config([
        'kraite.can_dispatch_steps' => true,
        'kraite.notifications_enabled' => true,
    ]);
    Notification::fake();
    Illuminate\Support\Once::flush();
    Cache::forget('system_health_alert-signal:dispatcher_tick_stale_default');
    MaintenanceMode::resumeStepsDispatch('');
});

function seedPendingDispatcherWork(): void
{
    Step::create([
        'class' => 'App\\Jobs\\PendingDispatcherHealthTestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'alpha',
        'index' => null,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);
}

it('alerts when active work has no recent dispatcher tick', function (): void {
    seedPendingDispatcherWork();

    StepsDispatcher::updateOrCreate(
        ['group' => 'alpha'],
        [
            'can_dispatch' => true,
            'last_tick_completed' => now()->subMinutes(2),
        ],
    );

    expect(app(CheckSystemHealthCommand::class)->checkDispatcherTickRate())->toBe(1);

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification): bool => ($notification->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($notification->title ?? ''), 'dispatcher ticks are stale'),
    );
});

it('stays silent when active work has a recent dispatcher tick', function (): void {
    seedPendingDispatcherWork();

    StepsDispatcher::updateOrCreate(
        ['group' => 'alpha'],
        [
            'can_dispatch' => true,
            'last_tick_completed' => now(),
        ],
    );

    expect(app(CheckSystemHealthCommand::class)->checkDispatcherTickRate())->toBe(0);

    Notification::assertNothingSent();
});

it('stays silent when there is no active dispatcher work', function (): void {
    expect(app(CheckSystemHealthCommand::class)->checkDispatcherTickRate())->toBe(0);

    Notification::assertNothingSent();
});

it('runs the dispatcher tick-rate check in every standard health pass', function (): void {
    expect(SystemHealthCheckType::standardCases())
        ->toContain(SystemHealthCheckType::DispatcherTickRate);
});
