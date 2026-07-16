<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Exceptions\SystemFrozenException;
use Kraite\Core\Jobs\Fleet\ReportFleetMetricsJob;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use Kraite\Core\Support\ApiClients\REST\BinanceApiClient;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use StepDispatcher\Models\Step;

final class FreezeProbeQueueJob extends BaseQueueableJob
{
    public bool $computed = false;

    public function compute(): void
    {
        $this->computed = true;
    }
}

beforeEach(function (): void {
    config([
        'kraite.freeze.marker_path' => storage_path('framework/testing/kraite-frozen-'.Str::uuid()),
    ]);

    Route::get('/freeze-traffic-probe', static fn (): string => 'ok');
});

afterEach(function (): void {
    File::delete(FreezeMode::markerPath());
});

it('blocks outbound Laravel HTTP without sending a request while frozen', function (): void {
    Http::fake();
    FreezeMode::activate();

    expect(fn () => Http::get('https://example.com/forbidden'))
        ->toThrow(SystemFrozenException::class);

    Http::assertNothingSent();
});

it('blocks exchange clients before an API audit row or network request is created', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $client = new BinanceApiClient([
        'url' => 'https://fapi.binance.test',
        'api_key' => 'TESTKEY',
        'api_secret' => 'TESTSECRET',
    ]);

    Http::fake();
    FreezeMode::activate();

    expect(fn () => $client->signRequest(ApiRequest::make(
        'GET',
        '/fapi/v1/openOrders',
        ApiProperties::make(['options' => ['symbol' => 'BTCUSDT']]),
    )))->toThrow(SystemFrozenException::class);

    expect(ApiRequestLog::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not seed self-rescheduling queue work while frozen', function (): void {
    Queue::fake();
    FreezeMode::activate();

    ReportFleetMetricsJob::seed('local');

    Queue::assertNothingPushed();
});

it('does not begin a pulled step job while frozen', function (): void {
    $job = new FreezeProbeQueueJob;
    $job->step = new Step;
    FreezeMode::activate();

    $job->handle();

    expect($job->computed)->toBeFalse();
});

it('does not start WebSocket daemon connections while frozen', function (): void {
    FreezeMode::activate();

    $this->artisan('kraite:stream-binance-prices')
        ->expectsOutputToContain('waiting without connecting')
        ->assertSuccessful();

    $this->artisan('kraite:stream-binance-user-data')
        ->expectsOutputToContain('waiting without connecting')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('suppresses notifications before delivery or audit side effects', function (): void {
    $user = User::factory()->create();
    FreezeMode::activate();

    expect(NotificationService::send($user, 'any-canonical'))
        ->toBeFalse()
        ->and(NotificationLog::query()->count())
        ->toBe(0);
});

it('blocks external inbound traffic while keeping localhost UI available', function (): void {
    FreezeMode::activate();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('/freeze-traffic-probe')
        ->assertServiceUnavailable();

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/freeze-traffic-probe')
        ->assertOk()
        ->assertSeeText('ok');
});
