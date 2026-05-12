<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Listeners\NotificationLogListener;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the relatable-leak fix.
 *
 * Before the fix, NotificationService set $user->relatable = $relatable as a
 * dynamic property on the (process-cached) Kraite::admin() User. Consecutive
 * admin sends without a relatable would inherit the previous send's relatable
 * in audit logs because nothing ever cleared it.
 *
 * After the fix, relatable travels on the per-send AlertNotification
 * instance, and NotificationLogListener::extractRelatable prefers
 * $notification->relatable over the notifiable's dynamic property. Because
 * each AlertNotification is freshly constructed per send, no leakage is
 * possible.
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->group('unit', 'notifications', 'relatable');

beforeEach(function (): void {
    \Kraite\Core\Models\Notification::factory()->serverIpForbidden()->create();
    \Kraite\Core\Models\Notification::factory()->serverRateLimitExceeded()->create();
});

it('AlertNotification stores the relatable on the per-send instance', function (): void {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);

    $notification = new AlertNotification(
        message: 'body',
        title: 'title',
        canonical: 'server_ip_forbidden',
        relatable: $apiSystem,
    );

    expect($notification->relatable)->toBe($apiSystem);
});

it('AlertNotification defaults relatable to null when not provided', function (): void {
    $notification = new AlertNotification(
        message: 'body',
        title: 'title',
        canonical: 'server_ip_forbidden',
    );

    expect($notification->relatable)->toBeNull();
});

it('NotificationLogListener writes audit row using notification->relatable, not the notifiable', function (): void {
    Notification::fake();

    $user = User::factory()->create(['notification_channels' => ['mail']]);
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);

    // Simulate stale dynamic property on the notifiable (the old leak path).
    // The listener must IGNORE this in favour of the per-send notification's
    // own relatable.
    $staleApiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    $user->relatable = $staleApiSystem;

    $notification = new AlertNotification(
        message: 'body',
        title: 'title',
        canonical: 'server_ip_forbidden',
        relatable: $apiSystem,
    );

    $event = new NotificationSent(
        notifiable: $user,
        notification: $notification,
        channel: 'mail',
        response: null,
    );

    (new NotificationLogListener)->handleNotificationSent($event);

    $log = NotificationLog::query()
        ->where('canonical', 'server_ip_forbidden')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->relatable_type)->toBe(ApiSystem::class);
    expect($log->relatable_id)->toBe($apiSystem->id);
    expect($log->relatable_id)->not->toBe($staleApiSystem->id);
});

it('Two consecutive admin notifications with different relatables do not cross-contaminate audit logs', function (): void {
    Notification::fake();

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );

    $apiSystemA = ApiSystem::factory()->create(['canonical' => 'binance']);
    $apiSystemB = ApiSystem::factory()->create(['canonical' => 'bybit']);

    $adminUser = Kraite::admin();

    // Send A — relatable = $apiSystemA
    $notificationA = new AlertNotification(
        message: 'a',
        title: 'A',
        canonical: 'server_ip_forbidden',
        relatable: $apiSystemA,
    );
    (new NotificationLogListener)->handleNotificationSent(new NotificationSent(
        notifiable: $adminUser,
        notification: $notificationA,
        channel: 'mail',
        response: null,
    ));

    // Send B — relatable = $apiSystemB (different model, same canonical class type)
    $notificationB = new AlertNotification(
        message: 'b',
        title: 'B',
        canonical: 'server_rate_limit_exceeded',
        relatable: $apiSystemB,
    );
    (new NotificationLogListener)->handleNotificationSent(new NotificationSent(
        notifiable: $adminUser,
        notification: $notificationB,
        channel: 'mail',
        response: null,
    ));

    $logs = NotificationLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2);
    expect($logs[0]->canonical)->toBe('server_ip_forbidden');
    expect($logs[0]->relatable_id)->toBe($apiSystemA->id);
    expect($logs[1]->canonical)->toBe('server_rate_limit_exceeded');
    expect($logs[1]->relatable_id)->toBe($apiSystemB->id);
    expect($logs[1]->relatable_id)->not->toBe($apiSystemA->id);
});

it('Send without a relatable produces a null relatable in audit log even after a prior send had one', function (): void {
    Notification::fake();

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );

    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $adminUser = Kraite::admin();

    // First send: with relatable
    (new NotificationLogListener)->handleNotificationSent(new NotificationSent(
        notifiable: $adminUser,
        notification: new AlertNotification(
            message: 'with',
            title: 'with',
            canonical: 'server_ip_forbidden',
            relatable: $apiSystem,
        ),
        channel: 'mail',
        response: null,
    ));

    // Second send: NO relatable. Pre-fix this would inherit $apiSystem from
    // the cached admin User instance.
    (new NotificationLogListener)->handleNotificationSent(new NotificationSent(
        notifiable: $adminUser,
        notification: new AlertNotification(
            message: 'without',
            title: 'without',
            canonical: 'server_rate_limit_exceeded',
        ),
        channel: 'mail',
        response: null,
    ));

    $second = NotificationLog::query()
        ->where('canonical', 'server_rate_limit_exceeded')
        ->first();

    expect($second)->not->toBeNull();
    expect($second->relatable_type)->toBeNull();
    expect($second->relatable_id)->toBeNull();
});
