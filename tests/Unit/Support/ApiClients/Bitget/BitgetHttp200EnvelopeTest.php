<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiClients\REST\BitgetApiClient;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

uses()->group('unit', 'bitget', 'api-client', 'http-200-envelope');

beforeEach(function (): void {
    $this->apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget HTTP 200 Test',
    ]);

    $this->client = new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => 'BITGET_TEST_KEY',
        'api_secret' => 'BITGET_TEST_SECRET',
        'passphrase' => 'BITGET_TEST_PASSPHRASE',
    ]);
});

function bitgetHttp200TestRequest(string $path = '/api/v2/mix/market/time'): ApiRequest
{
    return ApiRequest::make('GET', $path, new ApiProperties);
}

function bitgetHttp200Log(ApiSystem $apiSystem, string $path = '/api/v2/mix/market/time'): ApiRequestLog
{
    return ApiRequestLog::query()
        ->where('api_system_id', $apiSystem->id)
        ->where('path', $path)
        ->sole();
}

function bitgetHttp200RequestException(BitgetApiClient $client): RequestException
{
    try {
        $client->publicRequest(bitgetHttp200TestRequest());
    } catch (RequestException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected Bitget HTTP 200 response to throw a request exception.');
}

it('accepts code 00000, preserves the response body, and records success', function (): void {
    $payload = [
        'code' => '00000',
        'msg' => 'success',
        'requestTime' => 1_770_000_000_000,
        'data' => ['serverTime' => 1_770_000_000_000],
    ];

    Http::fake(['*' => Http::response($payload, 200)]);

    expect(ApiRequestLog::query()
        ->where('api_system_id', $this->apiSystem->id)
        ->where('path', '/api/v2/mix/market/time')
        ->exists())->toBeFalse();

    $response = $this->client->publicRequest(bitgetHttp200TestRequest());
    $log = bitgetHttp200Log($this->apiSystem);

    expect(json_decode((string) $response->getBody(), associative: true))->toBe($payload)
        ->and($log->http_response_code)->toBe(200)
        ->and($log->response)->toBe($payload)
        ->and($log->payload)->toBeNull();

    Http::assertSentCount(1);
});

it('converts a non-success Bitget envelope into a request exception before mappers consume it', function (): void {
    $payload = [
        'code' => '40774',
        'msg' => 'The order type for unilateral position must also be the unilateral position type',
        'requestTime' => 1_770_000_000_001,
        'data' => null,
    ];

    Http::fake(['*' => Http::response($payload, 200)]);

    $exception = bitgetHttp200RequestException($this->client);
    $log = bitgetHttp200Log($this->apiSystem);

    expect($exception)->toBeInstanceOf(RequestException::class)
        ->and($exception->getMessage())->toBe(
            'Bitget API error (code 40774): The order type for unilateral position must also be the unilateral position type'
        )
        ->and($exception->getResponse()?->getStatusCode())->toBe(200)
        ->and(json_decode((string) $exception->getResponse()?->getBody(), associative: true))->toBe($payload)
        ->and($log->http_response_code)->toBe(200)
        ->and($log->response)->toBe($payload)
        ->and($log->payload)->not->toBeNull();

    Http::assertSentCount(1);
});

it('fails closed when a Bitget HTTP 200 response lacks the required envelope code', function (mixed $payload): void {
    Http::fake(['*' => Http::response($payload, 200)]);

    $exception = bitgetHttp200RequestException($this->client);

    expect($exception)->toBeInstanceOf(RequestException::class)
        ->and($exception->getMessage())->toBe('Bitget API error: malformed HTTP 200 response envelope')
        ->and($exception->getResponse()?->getStatusCode())->toBe(200);

    Http::assertSentCount(1);
})->with([
    'missing code' => [['msg' => 'success', 'data' => []]],
    'invalid code type' => [['code' => [], 'msg' => 'success', 'data' => []]],
    'non-json body' => ['not-json'],
]);

it('retries a retryable Bitget envelope once and accepts the succeeding response', function (): void {
    $maintenance = [
        'code' => '45001',
        'msg' => 'System maintenance',
        'requestTime' => 1_770_000_000_002,
        'data' => null,
    ];
    $success = [
        'code' => '00000',
        'msg' => 'success',
        'requestTime' => 1_770_000_000_003,
        'data' => ['serverTime' => 1_770_000_000_003],
    ];

    Http::fakeSequence()
        ->push($maintenance, 200)
        ->push($success, 200);

    $response = $this->client->publicRequest(bitgetHttp200TestRequest());
    $log = bitgetHttp200Log($this->apiSystem);

    expect(json_decode((string) $response->getBody(), associative: true))->toBe($success)
        ->and($log->response)->toBe($success)
        ->and($log->payload)->toBeNull();

    Http::assertSentCount(2);
});

it('rejects a second retryable Bitget error instead of recording the retry as success', function (): void {
    $firstFailure = [
        'code' => '45001',
        'msg' => 'System maintenance',
        'requestTime' => 1_770_000_000_004,
        'data' => null,
    ];
    $secondFailure = [
        'code' => '40725',
        'msg' => 'System release error',
        'requestTime' => 1_770_000_000_005,
        'data' => null,
    ];

    Http::fakeSequence()
        ->push($firstFailure, 200)
        ->push($secondFailure, 200);

    $exception = bitgetHttp200RequestException($this->client);
    $log = bitgetHttp200Log($this->apiSystem);

    expect($exception)->toBeInstanceOf(RequestException::class)
        ->and($exception->getMessage())->toBe('Bitget API error (code 40725): System release error')
        ->and(json_decode((string) $exception->getResponse()?->getBody(), associative: true))->toBe($secondFailure)
        ->and($log->response)->toBe($secondFailure)
        ->and($log->payload)->not->toBeNull();

    Http::assertSentCount(2);
});
