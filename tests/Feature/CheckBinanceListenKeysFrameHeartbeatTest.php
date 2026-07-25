<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Kraite\Core\Commands\Daemons\StreamBinanceUserDataCommand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\BinanceListenKey;
use Kraite\Core\Support\NotificationMessageBuilder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => false]);
    $this->freezeTime();
});

function createFrameHeartbeatFixture(?string $lastFrameAt): array
{
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => 'stream-key',
        'binance_api_secret' => 'stream-secret',
        'updated_at' => now()->subHour(),
    ]);

    $listenKey = BinanceListenKey::create([
        'account_id' => $account->id,
        'listen_key' => 'listen-key',
        'last_created_at' => now()->subHour(),
        'last_keep_alive_at' => now(),
        'last_frame_at' => $lastFrameAt,
        'last_keep_alive_status' => 'success',
        'failure_count' => 0,
    ]);

    return [$account, $listenKey];
}

it('alerts when per-account socket activity is stale despite a fresh keepalive', function (): void {
    createFrameHeartbeatFixture(now()->subMinutes(20)->toDateTimeString());

    Artisan::call('kraite:cron-check-binance-listen-keys-stale', [
        '--max-frame-staleness-minutes' => 15,
        '--output' => true,
    ]);

    expect(Artisan::output())->toContain('stale_frame_heartbeat');
});

it('accepts a fresh protocol activity heartbeat', function (): void {
    createFrameHeartbeatFixture(now()->subMinutes(2)->toDateTimeString());

    Artisan::call('kraite:cron-check-binance-listen-keys-stale', [
        '--max-frame-staleness-minutes' => 15,
        '--output' => true,
    ]);

    expect(Artisan::output())->toContain('fresh listenKey and socket heartbeats');
});

it('persists protocol pings so quiet accounts remain observably healthy', function (): void {
    [$account, $listenKey] = createFrameHeartbeatFixture(null);
    $command = new StreamBinanceUserDataCommand;
    $command->injectSlotForTest($account->id, [
        'client' => null,
        'listen_key' => 'listen-key',
        'last_frame_persisted_at' => 0.0,
    ]);

    $callbacks = (new ReflectionClass($command))
        ->getMethod('connectionCallbacks')
        ->invoke($command, $account);

    $callbacks['ping']();

    expect($listenKey->fresh()->last_frame_at?->timestamp)->toBe(now()->timestamp);
});

it('renders the watchdog notification instead of dropping an unknown canonical', function (): void {
    $message = NotificationMessageBuilder::build('binance_listen_key_stale', [
        'account_id' => 7,
        'account_name' => 'Primary',
        'reason' => 'stale_frame_heartbeat',
        'detail' => 'No protocol activity for 20 minutes.',
    ]);

    expect($message['title'])->toContain('Primary')
        ->and($message['emailMessage'])->toContain('stale_frame_heartbeat')
        ->and($message['emailMessage'])->toContain('20 minutes');
});
