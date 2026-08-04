<?php

declare(strict_types=1);

use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDefinition;
use Kraite\Core\Models\Server;
use Kraite\Core\Models\User;
use Kraite\Core\Support\NotificationService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'kraite.notifications_enabled' => true,
        'kraite.freeze.marker_path' => storage_path('framework/testing/notification-delivery-'.Str::uuid()),
    ]);

    Kraite::query()->updateOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'notification_channels' => ['mail'],
            'notifications_enabled' => true,
        ],
    );

    Server::query()->firstOrCreate(
        ['hostname' => gethostname()],
        [
            'ip_address' => '127.0.0.1',
            'is_apiable' => false,
            'needs_whitelisting' => false,
            'type' => 'app',
        ],
    );

    Cache::flush();
    NotificationService::flushNotificationCache();
});

it('releases a cache throttle claim when delivery throws', function (): void {
    NotificationDefinition::factory()->serverRateLimitExceeded()->create([
        'cache_duration' => 300,
        'cache_key' => ['api_system'],
    ]);

    $user = User::factory()->create(['notifications_enabled' => true]);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('Channel unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);

    $firstAttempt = NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        cacheKeys: ['api_system' => 'binance'],
    );

    expect($firstAttempt)->toBeFalse()
        ->and(Cache::has('server_rate_limit_exceeded-api_system:binance'))->toBeFalse();

    $notificationFake = Notification::fake();
    app()->instance(Dispatcher::class, $notificationFake);

    $retry = NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        cacheKeys: ['api_system' => 'binance'],
    );

    expect($retry)->toBeTrue()
        ->and(Cache::has('server_rate_limit_exceeded-api_system:binance'))->toBeTrue();
});

it('refreshes notification settings in a long-running process', function (): void {
    $definition = NotificationDefinition::factory()->serverRateLimitExceeded()->create([
        'is_active' => false,
    ]);
    $user = User::factory()->create(['notifications_enabled' => true]);
    Notification::fake();

    expect(NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0,
    ))->toBeFalse();

    $definition->update(['is_active' => true]);

    expect(NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0,
    ))->toBeFalse();

    $this->travel(61)->seconds();

    expect(NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0,
    ))->toBeTrue();
});

it('atomically deduplicates the default database-throttled delivery window', function (): void {
    NotificationDefinition::factory()->serverRateLimitExceeded()->create([
        'cache_duration' => 300,
        'cache_key' => null,
    ]);
    $user = User::factory()->create(['notifications_enabled' => true]);

    Notification::fake();

    $first = NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
    );
    $racingAttempt = NotificationService::send(
        user: $user,
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
    );

    expect($first)->toBeTrue()
        ->and($racingAttempt)->toBeFalse();

    Notification::assertCount(1);
});
