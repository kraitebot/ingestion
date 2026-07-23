<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob as AtomicSyncPositionOrdersJob;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Notifications\AlertNotification;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

uses(RefreshDatabase::class);

/**
 * Pin the stale-`syncing` position wedge detector on `CheckSystemHealthCommand`.
 *
 * SyncPositionOrdersJob flips a position to `syncing` and only flips back to
 * `active` on the success path (intentional — see SyncPositionOrdersJob.php).
 * If the retry chain exhausts, the position stays in `syncing`
 * indefinitely with no automated recovery. This check detects those wedges
 * and alerts via the shared `system_health_alert` canonical.
 */
beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
    Illuminate\Support\Once::flush();

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );
});

it('does not fire stale_syncing_position when no positions are in syncing state', function (): void {
    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification) => str_starts_with(
            (string) ($notification->additionalParameters['signal'] ?? ''),
            'stale_syncing_position_'
        ) || str_contains(
            (string) ($notification->message ?? ''),
            'stale_syncing_position_'
        )
    );
});

it('does not fire stale_syncing_position when a syncing position has a live sync step', function (): void {
    // Position in 'syncing' for >15min, but a Pending sync step still exists
    // — the wedge detector should treat this as in-flight, not wedged.
    $position = Position::factory()->long()->create(['status' => 'syncing']);
    // Bypass observers/auto-timestamps so updated_at reflects the wedge age.
    Illuminate\Support\Facades\DB::table('positions')
        ->where('id', $position->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    Step::create([
        'class' => AtomicSyncPositionOrdersJob::class,
        'queue' => 'positions',
        'state' => Pending::class,
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'arguments' => ['positionId' => $position->id],
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification) => ($notification->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($notification->title ?? ''), "Position #{$position->id}")
    );
});

it('does not let an argument-only legacy row suppress a stale syncing alert after the production audit', function (): void {
    $position = Position::factory()->long()->create(['status' => 'syncing']);
    Illuminate\Support\Facades\DB::table('positions')
        ->where('id', $position->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    Step::create([
        'class' => AtomicSyncPositionOrdersJob::class,
        'queue' => 'positions',
        'state' => Pending::class,
        'arguments' => ['positionId' => $position->id],
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification) => ($notification->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($notification->title ?? ''), "Position #{$position->id} wedged in 'syncing'")
    );
});

it('does not fire stale_syncing_position when the syncing duration is below the 15-min threshold', function (): void {
    $belowThreshold = Position::factory()->long()->create(['status' => 'syncing']);
    Illuminate\Support\Facades\DB::table('positions')
        ->where('id', $belowThreshold->id)
        ->update(['updated_at' => now()->subMinutes(10)]);  // Below 15-min threshold

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification) => ($notification->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($notification->title ?? ''), "wedged in 'syncing'")
    );
});

it('fires stale_syncing_position when a position is wedged > 15min with no live sync step', function (): void {
    $position = Position::factory()->long()->create(['status' => 'syncing']);
    // Bypass observers/auto-timestamps so updated_at reflects the wedge age.
    Illuminate\Support\Facades\DB::table('positions')
        ->where('id', $position->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($notification) => ($notification->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($notification->title ?? ''), "Position #{$position->id} wedged in 'syncing'")
    );
});

it('registers the stale-syncing check in the standard health checks', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect(\Kraite\Core\Support\Health\SystemHealthCheckType::standardCases())
        ->toContain(\Kraite\Core\Support\Health\SystemHealthCheckType::StaleSyncingPositions);
    expect($source)->toContain('STALE_SYNCING_POSITION_MINUTES');
    expect($source)->toContain('stale_syncing_position_');
});
