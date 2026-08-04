<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Kraite\Core\Models\Kraite;

function loadUnlockedIngestionSchedule(): Schedule
{
    config(['kraite.server_role' => 'ingestion']);
    Kraite::query()->update(['is_cooling_down' => false]);
    require base_path('routes/console.php');

    return app(Schedule::class);
}

it('gives every overlap mutex an explicit bounded recovery lease', function (): void {
    $overlapEvents = collect(loadUnlockedIngestionSchedule()->events())
        ->filter(fn (Event $event): bool => $event->withoutOverlapping);

    expect($overlapEvents)->not->toBeEmpty();

    $defaultDayLongLeases = $overlapEvents
        ->filter(fn (Event $event): bool => $event->expiresAt === 1440)
        ->map(fn (Event $event): string => $event->command)
        ->values()
        ->all();

    expect($defaultDayLongLeases)->toBe([]);
});

it('recovers critical frequent schedule locks before their next useful tick', function (): void {
    $events = collect(loadUnlockedIngestionSchedule()->events());
    $expectedMaximums = [
        'kraite:cron-refresh-binance-listen-keys' => 2,
        'steps:recover-stale --recover-dispatched' => 2,
        'steps:recover-stale --prefix=trading' => 2,
        'kraite:cron-create-positions' => 3,
        'kraite:cron-sync-orders' => 5,
        'kraite:cron-check-drifts' => 5,
    ];

    foreach ($expectedMaximums as $command => $maximumMinutes) {
        $event = $events->first(
            fn (Event $event): bool => str_contains($event->command, $command)
        );

        expect($event)->not->toBeNull()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBeLessThanOrEqual($maximumMinutes);
    }
});
