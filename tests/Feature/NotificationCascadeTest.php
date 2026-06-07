<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\NotificationService;

/**
 * Notification delivery cascade:
 *   Tier 1 — global kraite.notifications_enabled: false suppresses ALL.
 *   Tier 2 — per-user users.notifications_enabled: false suppresses that
 *            user's notifications, EXCEPT Critical severity, which is
 *            always delivered. NULL counts as enabled.
 */
function cascadeAlert(string $severity): array
{
    return [
        'signal' => 'redis_down',
        'severity' => $severity,
        'title' => 'Cascade test alert',
        'detail' => 'Synthetic alert for the notification cascade test.',
        'detected_at' => now()->toIso8601String(),
    ];
}

it('suppresses every notification when the global switch is off — even critical', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => false]);
    $user = User::factory()->create(['notifications_enabled' => true]);

    NotificationService::send($user, 'system_health_alert', cascadeAlert('critical'));

    NotificationFacade::assertNothingSent();
});

it('delivers to an enabled user when the global switch is on', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => true]);
    $user = User::factory()->create(['notifications_enabled' => true]);

    NotificationService::send($user, 'system_health_alert', cascadeAlert('medium'));

    NotificationFacade::assertSentTo($user, AlertNotification::class);
});

it('suppresses non-critical notifications for a user who opted out', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => true]);
    $user = User::factory()->create(['notifications_enabled' => false]);

    NotificationService::send($user, 'system_health_alert', cascadeAlert('medium'));

    NotificationFacade::assertNothingSent();
});

it('always delivers critical notifications even to an opted-out user', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => true]);
    $user = User::factory()->create(['notifications_enabled' => false]);

    NotificationService::send($user, 'system_health_alert', cascadeAlert('critical'));

    NotificationFacade::assertSentTo($user, AlertNotification::class);
});

it('treats a null per-user flag as enabled', function (): void {
    NotificationFacade::fake();
    Kraite::query()->update(['notifications_enabled' => true]);
    $user = User::factory()->create(['notifications_enabled' => null]);

    NotificationService::send($user, 'system_health_alert', cascadeAlert('medium'));

    NotificationFacade::assertSentTo($user, AlertNotification::class);
});
