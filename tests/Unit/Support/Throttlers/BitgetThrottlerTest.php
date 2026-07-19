<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiClients\REST\BitgetApiClient;
use Kraite\Core\Support\Throttlers\BitgetThrottler;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

uses()->group('unit', 'bitget', 'throttler');

beforeEach(function (): void {
    Cache::flush();
    seedKraiteServerIpCache();

    config()->set('kraite.throttlers.bitget.safety_threshold', 1.0);
    config()->set('kraite.throttlers.bitget.min_delay_ms', 0);
    config()->set('kraite.throttlers.bitget.aggregate_requests_per_minute', 6_000);
});

afterEach(function (): void {
    Cache::flush();
    seedKraiteServerIpCache();
});

it('paces each Bitget endpoint at its documented limit', function (
    string $path,
    ?string $apiKey,
    int $expectedDelayMs,
): void {
    Sleep::assertNeverSlept();

    BitgetThrottler::throttleRequest($path, $apiKey);

    Sleep::assertNeverSlept();

    BitgetThrottler::throttleRequest($path, $apiKey);

    Sleep::assertSequence([
        Sleep::for($expectedDelayMs)->milliseconds(),
    ]);
})->with([
    'public market endpoint is limited to 20 requests per second per IP' => [
        '/api/v2/mix/market/contracts?productType=USDC-FUTURES',
        null,
        50,
    ],
    'public position tier endpoint is limited to 10 requests per second per IP' => [
        '/api/v2/mix/market/query-position-lever?productType=USDC-FUTURES',
        null,
        100,
    ],
    'regular private endpoint is limited to 10 requests per second per UID' => [
        '/api/v2/mix/order/place-order',
        'UID_A_KEY',
        100,
    ],
    'positions endpoint is limited to 5 requests per second per UID' => [
        '/api/v2/mix/position/all-position?productType=USDC-FUTURES',
        'UID_A_KEY',
        200,
    ],
    'leverage endpoint is limited to 5 requests per second per UID' => [
        '/api/v2/mix/account/set-leverage',
        'UID_A_KEY',
        200,
    ],
    'margin endpoint is limited to 5 requests per second per UID' => [
        '/api/v2/mix/account/set-margin-mode',
        'UID_A_KEY',
        200,
    ],
    'flash close endpoint is limited to 1 request per second per UID' => [
        '/api/v2/mix/order/close-positions',
        'UID_A_KEY',
        1_000,
    ],
    'position history endpoint is limited to 20 requests per second per UID' => [
        '/api/v2/mix/position/history-position',
        'UID_A_KEY',
        50,
    ],
]);

it('conservatively shares a private endpoint budget when Bitget UID cannot be derived', function (): void {
    BitgetThrottler::throttleRequest('/api/v2/mix/order/place-order', 'UID_A_KEY');
    BitgetThrottler::throttleRequest('/api/v2/mix/order/place-order', 'UID_B_KEY');

    Sleep::assertSequence([
        Sleep::for(100)->milliseconds(),
    ]);
});

it('enforces Bitget aggregate IP pacing across otherwise independent endpoints', function (): void {
    BitgetThrottler::throttleRequest('/api/v2/mix/order/place-order', 'UID_A_KEY');
    BitgetThrottler::throttleRequest('/api/v2/mix/order/cancel-order', 'UID_A_KEY');

    Sleep::assertSequence([
        Sleep::for(10)->milliseconds(),
    ]);
});

it('uses the documented five-minute default IP-ban recovery window', function (): void {
    BitgetThrottler::recordIpBan();

    expect(BitgetThrottler::isSafeToDispatch())->toBe(300_000);
});

it('applies the configured safety headroom below the vendor ceiling', function (): void {
    config()->set('kraite.throttlers.bitget.safety_threshold', 0.85);

    BitgetThrottler::throttleRequest('/api/v2/mix/order/place-order', 'UID_BUFFER_KEY');
    BitgetThrottler::throttleRequest('/api/v2/mix/order/place-order', 'UID_BUFFER_KEY');

    Sleep::assertSequence([
        Sleep::for(118)->milliseconds(),
    ]);
});

it('fails closed without sending HTTP when the atomic reservation lock is unavailable', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Locked Throttler Test',
    ]);

    $path = '/api/v2/mix/market/contracts';
    $scope = 'ip:'.hash('sha256', '127.0.0.1');
    $reservationKey = sprintf(
        'bitget_throttler:request:%s:%s',
        $scope,
        hash('sha256', $path)
    );
    $lock = Cache::lock($reservationKey.':lock', 60);

    expect($lock->get())->toBeTrue();

    Sleep::fake(syncWithCarbon: true);
    Http::fake(['*' => Http::response(['code' => '00000'], 200)]);

    $client = new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => 'UID_LOCK_KEY',
        'api_secret' => 'UID_LOCK_SECRET',
        'passphrase' => 'UID_LOCK_PASSPHRASE',
    ]);
    $request = ApiRequest::make(
        'GET',
        $path,
        ApiProperties::make([
            'options' => ['productType' => 'USDC-FUTURES'],
        ])
    );

    try {
        expect(fn () => $client->publicRequest($request))
            ->toThrow(LockTimeoutException::class);

        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('derives signed request identity at the HTTP boundary while sharing the safe private pace', function (): void {
    Sleep::fake(syncWithCarbon: true);

    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Signed Throttler Test',
    ]);

    $makeClient = static fn (string $apiKey): BitgetApiClient => new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => $apiKey,
        'api_secret' => 'UID_TEST_SECRET',
        'passphrase' => 'UID_TEST_PASSPHRASE',
    ]);
    $makeRequest = static fn (): ApiRequest => ApiRequest::make(
        'POST',
        '/api/v2/mix/order/place-order',
        ApiProperties::make([
            'options' => [
                'symbol' => 'BTCPERP',
                'productType' => 'USDC-FUTURES',
            ],
        ])
    );

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'msg' => 'success',
            'data' => ['orderId' => '123'],
        ], 200),
    ]);

    $uidA = $makeClient('UID_A_KEY');
    $uidB = $makeClient('UID_B_KEY');

    $uidA->signRequest($makeRequest());
    $uidB->signRequest($makeRequest());
    $uidA->signRequest($makeRequest());

    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(100)->milliseconds(),
        Sleep::for(100)->milliseconds(),
    ]);

    $sentRequests = Http::recorded()
        ->map(static fn (array $record): Illuminate\Http\Client\Request => $record[0])
        ->values();
    $firstTimestamp = (int) $sentRequests[0]->header('ACCESS-TIMESTAMP')[0];
    $thirdTimestamp = (int) $sentRequests[2]->header('ACCESS-TIMESTAMP')[0];
    $thirdExpectedSignature = base64_encode(hash_hmac(
        'sha256',
        $thirdTimestamp
            .'POST'
            .'/api/v2/mix/order/place-order'
            .json_encode([
                'symbol' => 'BTCPERP',
                'productType' => 'USDC-FUTURES',
            ], JSON_THROW_ON_ERROR),
        'UID_TEST_SECRET',
        true
    ));

    expect($thirdTimestamp - $firstTimestamp)->toBe(200)
        ->and($sentRequests[2]->header('ACCESS-SIGN')[0])
        ->not->toBe($sentRequests[0]->header('ACCESS-SIGN')[0])
        ->and($sentRequests[2]->header('ACCESS-SIGN')[0])->toBe($thirdExpectedSignature);
});

it('throttles every public HTTP attempt including an internal retry', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Throttler Retry Test',
    ]);

    $client = new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => 'UID_RETRY_KEY',
        'api_secret' => 'UID_RETRY_SECRET',
        'passphrase' => 'UID_RETRY_PASSPHRASE',
    ]);

    Http::fakeSequence()
        ->push([
            'code' => '45001',
            'msg' => 'System maintenance',
            'data' => null,
        ], 200)
        ->push([
            'code' => '00000',
            'msg' => 'success',
            'data' => [],
        ], 200);

    $request = ApiRequest::make(
        'GET',
        '/api/v2/mix/market/contracts',
        ApiProperties::make([
            'options' => ['productType' => 'USDC-FUTURES'],
        ])
    );

    $client->publicRequest($request);

    Http::assertSentCount(2);
    Sleep::assertSequence([
        Sleep::for(10)->seconds(),
        Sleep::for(50)->milliseconds(),
    ]);
});

it('re-reserves and re-signs every private HTTP retry', function (): void {
    Sleep::fake(syncWithCarbon: true);

    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Signed Retry Test',
    ]);

    $client = new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => 'UID_SIGNED_RETRY_KEY',
        'api_secret' => 'UID_SIGNED_RETRY_SECRET',
        'passphrase' => 'UID_SIGNED_RETRY_PASSPHRASE',
    ]);

    Http::fakeSequence()
        ->push([
            'code' => '45001',
            'msg' => 'System maintenance',
            'data' => null,
        ], 200)
        ->push([
            'code' => '00000',
            'msg' => 'success',
            'data' => ['orderId' => 'FIRST'],
        ], 200)
        ->push([
            'code' => '00000',
            'msg' => 'success',
            'data' => ['orderId' => 'SECOND'],
        ], 200);

    $makeRequest = static fn (): ApiRequest => ApiRequest::make(
        'POST',
        '/api/v2/mix/order/place-order',
        ApiProperties::make([
            'options' => [
                'symbol' => 'BTCPERP',
                'productType' => 'USDC-FUTURES',
            ],
        ])
    );

    $client->signRequest($makeRequest());
    $client->signRequest($makeRequest());

    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(10)->seconds(),
        Sleep::for(100)->milliseconds(),
    ]);

    $sentRequests = Http::recorded()
        ->map(static fn (array $record): Illuminate\Http\Client\Request => $record[0])
        ->values();
    $timestamps = $sentRequests
        ->map(static fn (Illuminate\Http\Client\Request $request): int => (int) $request->header('ACCESS-TIMESTAMP')[0])
        ->all();
    $signatures = $sentRequests
        ->map(static fn (Illuminate\Http\Client\Request $request): string => $request->header('ACCESS-SIGN')[0])
        ->all();

    expect($timestamps[1] - $timestamps[0])->toBe(10_000)
        ->and($timestamps[2] - $timestamps[1])->toBe(100)
        ->and(array_unique($signatures))->toHaveCount(3);
});
