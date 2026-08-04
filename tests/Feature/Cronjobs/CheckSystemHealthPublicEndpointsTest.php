<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Health\SystemHealthCheckType;

beforeEach(function (): void {
    config([
        'kraite.health_watchdog.public_endpoints' => [
            'kraite_home' => 'https://kraite.example/',
            'admin' => 'https://admin.kraite.example/',
        ],
        'kraite.health_watchdog.public_endpoint_connect_timeout_seconds' => 2,
        'kraite.health_watchdog.public_endpoint_timeout_seconds' => 5,
        'kraite.notifications_enabled' => true,
    ]);

    Notification::fake();
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_kraite_home');
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_admin');
});

afterEach(function (): void {
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_kraite_home');
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_admin');
});

it('checks every configured public endpoint and stays silent when each returns HTTP 200', function (): void {
    Http::fake([
        'https://kraite.example/' => Http::response('home', 200),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(0)
        ->and(SystemHealthCheckType::standardCases())->toContain(SystemHealthCheckType::PublicEndpoints);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://kraite.example/');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://admin.kraite.example/');
    Notification::assertNothingSent();
});

it('alerts with the exact endpoint and status while continuing the remaining checks', function (): void {
    Http::fake([
        'https://kraite.example/' => Http::response('unavailable', 503),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(1);

    Http::assertSentCount(2);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->canonical === 'system_health_alert'
            && str_contains($notification->title, 'kraite_home')
            && str_contains((string) $notification->pushoverMessage, 'https://kraite.example/')
            && str_contains((string) $notification->pushoverMessage, 'HTTP 503'),
    );
});

it('alerts on a connection failure without preventing later endpoint checks', function (): void {
    Http::fake([
        'https://kraite.example/' => Http::failedConnection('connection refused'),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(1);

    Http::assertSentCount(2);
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => str_contains($notification->title, 'kraite_home')
            && str_contains((string) $notification->pushoverMessage, 'connection refused'),
    );
});
