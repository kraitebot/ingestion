<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
        'kraite.health_watchdog.public_endpoint_maintenance_markers' => [],
        'kraite.health_watchdog.maintenance_stuck_minutes' => 45,
        'kraite.notifications_enabled' => true,
    ]);

    $this->publicEndpointMaintenanceMarkers = [];

    Notification::fake();
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_kraite_home');
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_admin');
});

afterEach(function (): void {
    foreach ($this->publicEndpointMaintenanceMarkers as $markerPath) {
        @unlink($markerPath);
    }

    clearstatcache();

    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_kraite_home');
    Cache::forget('system_health_alert-signal:public_endpoint_unhealthy_admin');
});

function createPublicEndpointMaintenanceMarker(object $testCase, int $ageSeconds = 0): string
{
    $markerPath = storage_path('framework/testing/public-endpoint-maintenance-'.Str::uuid());
    file_put_contents($markerPath, json_encode(['status' => 503], JSON_THROW_ON_ERROR));
    touch($markerPath, now()->timestamp - $ageSeconds);
    clearstatcache(true, $markerPath);

    $testCase->publicEndpointMaintenanceMarkers[] = $markerPath;

    return $markerPath;
}

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

it('suppresses only HTTP 503 endpoints whose sibling maintenance marker is fresh', function (): void {
    $websiteMarker = createPublicEndpointMaintenanceMarker($this, 60);

    config([
        'kraite.health_watchdog.public_endpoint_maintenance_markers' => [
            'kraite_home' => $websiteMarker,
        ],
    ]);

    Http::fake([
        'https://kraite.example/' => Http::response('planned maintenance', 503),
        'https://admin.kraite.example/' => Http::response('unexpected outage', 503),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(1);

    Http::assertSentCount(2);
    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => str_contains($notification->title, 'admin')
            && ! str_contains($notification->title, 'kraite_home'),
    );
});

it('suppresses every configured endpoint that shares the same fresh maintenance marker', function (): void {
    $websiteMarker = createPublicEndpointMaintenanceMarker($this, 60);

    config([
        'kraite.health_watchdog.public_endpoints' => [
            'kraite_home' => 'https://kraite.example/',
            'registration' => 'https://kraite.example/register',
            'admin' => 'https://admin.kraite.example/',
        ],
        'kraite.health_watchdog.public_endpoint_maintenance_markers' => [
            'kraite_home' => $websiteMarker,
            'registration' => $websiteMarker,
        ],
    ]);

    Http::fake([
        'https://kraite.example/' => Http::response('planned maintenance', 503),
        'https://kraite.example/register' => Http::response('planned maintenance', 503),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(0);

    Http::assertSentCount(3);
    Notification::assertNothingSent();
});

it('alerts when a sibling maintenance marker has exceeded the safe deployment window', function (): void {
    $staleMarker = createPublicEndpointMaintenanceMarker($this, (45 * 60) + 1);

    config([
        'kraite.health_watchdog.public_endpoint_maintenance_markers' => [
            'kraite_home' => $staleMarker,
        ],
    ]);

    Http::fake([
        'https://kraite.example/' => Http::response('stuck maintenance', 503),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(1);

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => str_contains($notification->title, 'kraite_home')
            && str_contains((string) $notification->pushoverMessage, 'HTTP 503'),
    );
});

it('does not hide a non-maintenance failure behind a fresh sibling marker', function (): void {
    $websiteMarker = createPublicEndpointMaintenanceMarker($this, 60);

    config([
        'kraite.health_watchdog.public_endpoint_maintenance_markers' => [
            'kraite_home' => $websiteMarker,
        ],
    ]);

    Http::fake([
        'https://kraite.example/' => Http::response('application failure', 500),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe(1);

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => str_contains($notification->title, 'kraite_home')
            && str_contains((string) $notification->pushoverMessage, 'HTTP 500'),
    );
});

it('uses the exact sibling-maintenance age boundary', function (int $ageSeconds, int $expectedAlerts): void {
    $websiteMarker = createPublicEndpointMaintenanceMarker($this, $ageSeconds);

    config([
        'kraite.health_watchdog.public_endpoint_maintenance_markers' => [
            'kraite_home' => $websiteMarker,
        ],
    ]);

    Http::fake([
        'https://kraite.example/' => Http::response('maintenance', 503),
        'https://admin.kraite.example/' => Http::response('admin', 200),
    ]);

    expect(app(CheckSystemHealthCommand::class)->checkPublicEndpoints())->toBe($expectedAlerts);

    if ($expectedAlerts === 0) {
        Notification::assertNothingSent();

        return;
    }

    Notification::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
})->with([
    'one second inside the window is expected maintenance' => [(45 * 60) - 1, 0],
    'the exact threshold is alertable' => [45 * 60, 1],
]);
