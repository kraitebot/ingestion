<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;

uses()->group('feature', 'cronjobs', 'system-health');

beforeEach(function (): void {
    $this->sharedHealthResourceLock = acquireKraiteTestLock('shared-system-health-resources');

    config(['kraite.notifications_enabled' => true]);
    MaintenanceMode::clearPostWarmupRecovery();
    Notification::fake();
    Illuminate\Support\Once::flush();

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ],
    );
});

afterEach(function (): void {
    MaintenanceMode::clearPostWarmupRecovery();
    releaseKraiteTestLock($this->sharedHealthResourceLock ?? null);
});

function makeStaleHealthSymbol(string $token): ExchangeSymbol
{
    $apiSystem = ApiSystem::query()->firstOrCreate(
        ['canonical' => 'binance'],
        [
            'name' => 'Binance',
            'is_exchange' => true,
            'recvwindow_margin' => 1_000,
        ],
    );

    return ExchangeSymbol::factory()
        ->taapiVerified()
        ->long()
        ->create([
            'api_system_id' => $apiSystem->id,
            'token' => $token,
            'quote' => 'USDT',
            'symbol_id' => random_int(100_000, 999_999),
            'leverage_brackets' => [['initialLeverage' => 20]],
            'indicators_timeframe' => '1h',
            'indicators_synced_at' => now()->subMinutes(121),
            'btc_correlation_pearson' => ['1h' => 0.8],
            'btc_correlation_spearman' => ['1h' => 0.8],
            'btc_correlation_rolling' => ['1h' => 0.8],
            'btc_correlation_stability' => ['1h' => 0.8],
            'btc_elasticity_long' => ['1h' => 1.0],
            'btc_elasticity_short' => ['1h' => 1.0],
        ]);
}

it('does not call an indicator stale while that exact symbol refresh is in progress', function (string $refreshJobClass): void {
    $refreshing = makeStaleHealthSymbol('REFRESHING'.Str::upper(Str::random(6)));
    $unattended = makeStaleHealthSymbol('UNATTENDED'.Str::upper(Str::random(6)));

    $expectedStaleAt = now()->subMinutes(121)->toDateTimeString();

    expect($refreshing->indicators_synced_at?->toDateTimeString())->toBe($expectedStaleAt)
        ->and($unattended->indicators_synced_at?->toDateTimeString())->toBe($expectedStaleAt);

    Step::create([
        'block_uuid' => Str::uuid()->toString(),
        'class' => $refreshJobClass,
        'queue' => 'indicators',
        'state' => Pending::class,
        'arguments' => [
            'exchangeSymbolId' => $refreshing->id,
            'timeframe' => '1h',
            'previousConclusions' => [],
        ],
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Indicators stale for {$refreshing->token}USDT",
    );
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Indicators stale for {$unattended->token}USDT",
    );
})->with([
    'query producer' => QuerySymbolIndicatorsJob::class,
    'conclusion producer' => ConcludeSymbolDirectionAtTimeframeJob::class,
]);

it('does not let a terminal indicator step hide genuine staleness', function (): void {
    $symbol = makeStaleHealthSymbol('TERMINAL'.Str::upper(Str::random(6)));

    Step::create([
        'block_uuid' => Str::uuid()->toString(),
        'class' => QuerySymbolIndicatorsJob::class,
        'queue' => 'indicators',
        'state' => Completed::class,
        'arguments' => [
            'exchangeSymbolId' => $symbol->id,
            'timeframe' => '1h',
            'previousConclusions' => [],
        ],
        'completed_at' => now(),
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Indicators stale for {$symbol->token}USDT",
    );
});

it('does not let an abandoned old indicator step hide genuine staleness', function (): void {
    $symbol = makeStaleHealthSymbol('ABANDONED'.Str::upper(Str::random(6)));

    $step = Step::create([
        'block_uuid' => Str::uuid()->toString(),
        'class' => QuerySymbolIndicatorsJob::class,
        'queue' => 'indicators',
        'state' => Pending::class,
        'arguments' => [
            'exchangeSymbolId' => $symbol->id,
            'timeframe' => '1h',
            'previousConclusions' => [],
        ],
    ]);
    $step->updateQuietly([
        'created_at' => now()->subMinutes(121),
        'updated_at' => now()->subMinutes(121),
    ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Indicators stale for {$symbol->token}USDT",
    );
});

it('suppresses dispatcher-derived stale alerts during post-warmup recovery only', function (): void {
    $account = Account::factory()->create([
        'name' => 'Recovery balance '.Str::uuid(),
        'is_active' => true,
    ]);
    $symbol = makeStaleHealthSymbol('RECOVERY'.Str::upper(Str::random(6)));

    ExchangeSymbolPrice::query()
        ->where('exchange_symbol_id', $symbol->id)
        ->update([
            'mark_price' => '1.00000000',
            'mark_price_synced_at' => now()->subSeconds(61),
        ]);

    expect(DB::table('account_balance_history')->where('account_id', $account->id)->exists())->toBeFalse();

    MaintenanceMode::startPostWarmupRecovery();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Account balance history stale (#{$account->id})"
            || $notification->title === "Indicators stale for {$symbol->token}USDT",
    );
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Mark price stale for {$symbol->token}USDT",
    );
});

it('alerts again after the post-warmup recovery marker is gone', function (): void {
    $account = Account::factory()->create([
        'name' => 'Unattended balance '.Str::uuid(),
        'is_active' => true,
    ]);

    MaintenanceMode::clearPostWarmupRecovery();

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->title === "Account balance history stale (#{$account->id})",
    );
});
