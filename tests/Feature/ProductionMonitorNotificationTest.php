<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDefinition;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\NotificationMessageBuilder;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\ProductionMonitorNotifier;
use NotificationChannels\Pushover\PushoverChannel;

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);

    Notification::fake();
    Cache::flush();
    NotificationService::flushNotificationCache();
    seedKraiteServerIpCache();
});

it('seeds a per-signal one-day throttle for non-critical production-monitor advisories', function (): void {
    $definition = NotificationDefinition::query()
        ->where('canonical', 'kraite_production_monitor')
        ->sole();

    expect($definition->cache_duration)->toBe(86_400)
        ->and($definition->cache_key)->toBe(['signal'])
        ->and($definition->is_active)->toBeTrue();
});

it('renders the exact operator issue fields through the governed notification path', function (): void {
    $payload = NotificationMessageBuilder::build('kraite_production_monitor', [
        'signal' => 'host_reboot_required',
        'issue' => 'production host reboot required',
        'cause' => 'new kernel pending',
        'action' => 'schedule bounded reboot',
        'executed' => false,
    ]);

    expect($payload['severity'])->toBe(NotificationSeverity::High)
        ->and($payload['title'])->toBe('KRAITE MONITOR')
        ->and($payload['pushoverMessage'])->toBe(
            '[KRAITE MONITOR] production host reboot required | cause: new kernel pending | action: schedule bounded reboot | executed: no | signal: host_reboot_required',
        );
});

it('delivers one unchanged advisory per day while allowing distinct signals immediately', function (): void {
    $notifier = app(ProductionMonitorNotifier::class);

    $first = $notifier->send(
        signal: 'host_reboot_required',
        issue: 'production host reboot required',
        cause: 'new kernel pending',
        action: 'schedule bounded reboot',
    );
    $duplicate = $notifier->send(
        signal: 'host_reboot_required',
        issue: 'production host reboot required',
        cause: 'same kernel still pending',
        action: 'schedule bounded reboot',
    );
    $differentIssue = $notifier->send(
        signal: 'disk_pressure_low',
        issue: 'production disk pressure',
        cause: 'free space below threshold',
        action: 'operator review',
    );

    expect($first)->toBeTrue()
        ->and($duplicate)->toBeFalse()
        ->and($differentIssue)->toBeTrue();

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 2);
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->forceChannels === [PushoverChannel::class]
            && str_contains((string) $notification->pushoverMessage, 'signal: disk_pressure_low'),
    );
});

it('lets one recovery message through without reopening the unresolved advisory throttle', function (): void {
    $notifier = app(ProductionMonitorNotifier::class);

    $first = $notifier->send(
        signal: 'host_reboot_required',
        issue: 'production host reboot required',
        cause: 'new kernel pending',
        action: 'schedule bounded reboot',
    );
    $recovery = $notifier->send(
        signal: 'host_reboot_required',
        issue: 'production host reboot requirement cleared',
        cause: 'running kernel now matches installed kernel',
        action: 'none',
        executed: true,
        resolved: true,
    );
    $duplicateRecovery = $notifier->send(
        signal: 'host_reboot_required',
        issue: 'production host reboot requirement cleared',
        cause: 'running kernel now matches installed kernel',
        action: 'none',
        executed: true,
        resolved: true,
    );

    expect($first)->toBeTrue()
        ->and($recovery)->toBeTrue()
        ->and($duplicateRecovery)->toBeFalse();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->severity === NotificationSeverity::Info
            && $notification->title === 'KRAITE MONITOR RECOVERY'
            && str_contains((string) $notification->pushoverMessage, 'signal: recovered:host_reboot_required'),
    );
});

it('reopens the unchanged advisory bucket after its one-day claim expires', function (): void {
    $notifier = app(ProductionMonitorNotifier::class);

    $send = fn (): bool => $notifier->send(
        signal: 'host_reboot_required',
        issue: 'production host reboot required',
        cause: 'new kernel pending',
        action: 'schedule bounded reboot',
    );

    expect($send())->toBeTrue();

    expect($send())->toBeFalse();

    Cache::forget('kraite_production_monitor-signal:host_reboot_required');
    expect($send())->toBeTrue();

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 2);
});

it('allows verified critical trading exposure to page on every monitor run', function (): void {
    $notifier = app(ProductionMonitorNotifier::class);

    $send = fn (): bool => $notifier->send(
        signal: 'position_protection_missing:4412',
        issue: 'position protection missing',
        cause: 'exchange stop order absent',
        action: 'protect position immediately',
        critical: true,
    );

    expect($send())->toBeTrue()
        ->and($send())->toBeTrue();

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 2);
});

it('rejects empty or unsafe monitor signals before notification delivery', function (string $signal): void {
    expect(fn (): bool => app(ProductionMonitorNotifier::class)->send(
        signal: $signal,
        issue: 'issue',
        cause: 'cause',
        action: 'action',
    ))->toThrow(InvalidArgumentException::class);

    Notification::assertNothingSent();
})->with([
    'empty' => '',
    'spaces' => 'host reboot required',
    'uppercase' => 'Host_Reboot_Required',
]);
