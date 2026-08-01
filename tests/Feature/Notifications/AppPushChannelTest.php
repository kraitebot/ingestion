<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AppPushDevice;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Notifications\Channels\AppPushChannel;
use Kraite\Core\Support\NotificationService;

beforeEach(function (): void {
    Kraite::find(1)->updateSaving(['notifications_enabled' => true]);
    config([
        'kraite.app_push.enabled' => true,
        'kraite.app_push.endpoint' => 'https://exp.host/--/api/v2/push/send',
    ]);

    Notification::query()->updateOrCreate(
        ['canonical' => 'market_regime_critical'],
        [
            'title' => 'BSCS Critical',
            'description' => 'BSCS critical test definition.',
            'default_severity' => NotificationSeverity::High,
            'cache_duration' => 0,
            'is_active' => true,
        ],
    );
});

it('adds app delivery to every persisted trader while leaving virtual admin channels unchanged', function (): void {
    $trader = User::factory()->create(['notification_channels' => ['mail']]);
    $notification = new AlertNotification(
        message: 'Trader body',
        title: 'Trader title',
        canonical: 'market_regime_critical',
    );

    expect($notification->via($trader))
        ->toBe(['mail', AppPushChannel::class])
        ->and($notification->via(Kraite::admin()))
        ->not->toContain(AppPushChannel::class);
});

it('sends an exact Expo payload and writes a trader-owned app history row', function (): void {
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => [['status' => 'ok', 'id' => 'expo-ticket-1']],
        ]),
    ]);
    $trader = User::factory()->create(['notification_channels' => []]);
    Account::factory()->create(['user_id' => $trader->id]);
    createPushDevice($trader, 'ExponentPushToken[phone_one]');

    expect(NotificationService::send(
        user: $trader,
        canonical: 'market_regime_critical',
        referenceData: ['score' => 91, 'cooldown_hours' => 12],
        duration: 0,
        channels: [AppPushChannel::class],
    ))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data()[0] ?? [];

        return $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $payload['to'] === 'ExponentPushToken[phone_one]'
            && $payload['title'] === 'BSCS Critical — 91/100, opens paused 12h'
            && $payload['body'] === 'BSCS Critical 91/100 — opens paused for 12h'
            && $payload['badge'] === 1
            && $payload['data']['canonical'] === 'market_regime_critical'
            && $payload['data']['screen'] === 'Dashboard'
            && $payload['data']['severity'] === 'high'
            && is_string($payload['data']['event_id'])
            && $payload['data']['event_id'] !== '';
    });

    $log = NotificationLog::query()->latest('id')->first();
    $content = json_decode($log->content_dump ?? '{}', true, flags: JSON_THROW_ON_ERROR);

    expect($log->user_id)
        ->toBe($trader->id)
        ->and($log->channel)
        ->toBe('app')
        ->and($log->recipient)
        ->toBe('iPhone app')
        ->and($log->message_id)
        ->toBe('expo-ticket-1')
        ->and($content['title'])
        ->toBe('BSCS Critical — 91/100, opens paused 12h')
        ->and($content['id'])
        ->toBeString()
        ->not->toBeEmpty();
});

it('still stores app history when the trader has no registered phone', function (): void {
    Http::fake();
    $trader = User::factory()->create(['notification_channels' => []]);
    Account::factory()->create(['user_id' => $trader->id]);

    expect(NotificationService::send(
        user: $trader,
        canonical: 'market_regime_critical',
        referenceData: ['score' => 80, 'cooldown_hours' => 12],
        duration: 0,
        channels: [AppPushChannel::class],
    ))->toBeTrue();

    Http::assertNothingSent();
    expect(NotificationLog::query()
        ->where('user_id', $trader->id)
        ->where('channel', 'app')
        ->where('canonical', 'market_regime_critical')
        ->count())->toBe(1);
});

it('disables only the Expo device reported as unregistered', function (): void {
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => [
                [
                    'status' => 'error',
                    'message' => 'Device is not registered',
                    'details' => ['error' => 'DeviceNotRegistered'],
                ],
                ['status' => 'ok', 'id' => 'expo-ticket-good'],
            ],
        ]),
    ]);
    $trader = User::factory()->create(['notification_channels' => []]);
    Account::factory()->create(['user_id' => $trader->id]);
    $bad = createPushDevice($trader, 'ExponentPushToken[phone_bad]');
    $good = createPushDevice($trader, 'ExponentPushToken[phone_good]');

    NotificationService::send(
        user: $trader,
        canonical: 'market_regime_critical',
        referenceData: ['score' => 99, 'cooldown_hours' => 12],
        duration: 0,
        channels: [AppPushChannel::class],
    );

    expect($bad->refresh()->disabled_at)
        ->not->toBeNull()
        ->and($good->refresh()->disabled_at)
        ->toBeNull();
});

it('preserves an existing operator notification toggle when definitions are refreshed', function (): void {
    Notification::query()
        ->where('canonical', 'market_regime_critical')
        ->update(['is_active' => false]);

    $corePath = InstalledVersions::getInstallPath('kraitebot/core');

    if ($corePath === null) {
        throw new RuntimeException('The installed kraitebot/core package path could not be resolved.');
    }

    $migration = require $corePath.'/database/migrations/2026_07_30_210454_add_app_push_notification_definitions.php';
    $migration->up();

    expect(Notification::query()
        ->where('canonical', 'market_regime_critical')
        ->value('is_active'))->toBeFalse();
});

function createPushDevice(User $trader, string $token): AppPushDevice
{
    return AppPushDevice::factory()->create([
        'user_id' => $trader->id,
        'expo_push_token' => $token,
        'token_hash' => hash('sha256', $token),
        'disabled_at' => null,
    ]);
}
