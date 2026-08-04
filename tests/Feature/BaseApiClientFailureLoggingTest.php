<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Commands\Cronjobs\CheckDriftsCommand;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;

function failingApiClient(ApiSystem $apiSystem, Throwable $failure, string $path): BaseApiClient
{
    return new class($apiSystem, $failure, $path) extends BaseApiClient
    {
        public function __construct(
            ApiSystem $apiSystem,
            private readonly Throwable $failure,
            private readonly string $path,
        ) {
            parent::__construct('https://api.example.test');
            $this->apiSystem = $apiSystem;
        }

        public function send(): ResponseInterface
        {
            return $this->processRequest(ApiRequest::make('GET', $this->path));
        }

        protected function getHeaders(): array
        {
            return [];
        }

        protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
        {
            usleep(2_000);

            throw $this->failure;
        }
    };
}

/** @param array<int, ResponseInterface|Throwable> $outcomes */
function sequencedApiClient(ApiSystem $apiSystem, array $outcomes, string $path): BaseApiClient
{
    return new class($apiSystem, $outcomes, $path) extends BaseApiClient
    {
        /** @param array<int, ResponseInterface|Throwable> $outcomes */
        public function __construct(
            ApiSystem $apiSystem,
            private array $outcomes,
            private readonly string $path,
        ) {
            parent::__construct('https://api.example.test');
            $this->apiSystem = $apiSystem;
            $this->exceptionHandler = BaseExceptionHandler::make('binance');
        }

        public function send(): ResponseInterface
        {
            return $this->processRequest(ApiRequest::make('GET', $this->path));
        }

        protected function getHeaders(): array
        {
            return [];
        }

        protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
        {
            usleep(2_000);
            $outcome = array_shift($this->outcomes);

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }
    };
}

function laravelHttpBackedApiClient(ApiSystem $apiSystem, string $path): BaseApiClient
{
    return new class($apiSystem, $path) extends BaseApiClient
    {
        public function __construct(ApiSystem $apiSystem, private readonly string $path)
        {
            parent::__construct('https://api.example.test');
            $this->apiSystem = $apiSystem;
        }

        public function send(): ResponseInterface
        {
            return $this->processRequest(ApiRequest::make('GET', $this->path));
        }

        protected function getHeaders(): array
        {
            return [];
        }
    };
}

function sensitivePayloadApiClient(ApiSystem $apiSystem, string $path, ApiProperties $properties): BaseApiClient
{
    return new class($apiSystem, $path, $properties) extends BaseApiClient
    {
        public function __construct(
            ApiSystem $apiSystem,
            private readonly string $path,
            private readonly ApiProperties $properties,
        ) {
            parent::__construct('https://api.example.test');
            $this->apiSystem = $apiSystem;
        }

        public function send(): ResponseInterface
        {
            return $this->processRequest(ApiRequest::make('POST', $this->path, $this->properties), true);
        }

        protected function getHeaders(): array
        {
            return [];
        }
    };
}

it('persists completed transport failure metadata and exposes it to the exchange safety guard', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $path = '/regression/curl-56';
    $failure = new RequestException(
        'cURL error 56: Recv failure: Connection reset by peer',
        new Request('GET', $path),
        null,
        null,
        ['errno' => 56],
    );

    expect(ApiRequestLog::query()->where('path', $path)->exists())->toBeFalse();

    expect(fn () => failingApiClient($apiSystem, $failure, $path)->send())
        ->toThrow(RequestException::class, 'cURL error 56');

    $log = ApiRequestLog::query()->where('path', $path)->sole();
    $expectedError = $failure->getMessage().' (line '.$failure->getLine().')';

    expect($log->http_response_code)->toBeNull()
        ->and($log->response)->toBeNull()
        ->and($log->error_message)->toBe($expectedError)
        ->and($log->getRawOriginal('completed_at'))->toBe(now()->format('Y-m-d H:i:s'))
        ->and($log->duration)->toBeGreaterThanOrEqual(1)
        ->and(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors(Carbon::now()->subMinute()))->toBe(1);
});

it('persists failure metadata for vendor errors returned inside HTTP 200 responses', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $path = '/regression/vendor-error';
    $failure = new RequestException(
        'Vendor rejected the request',
        new Request('GET', $path),
        new Response(200, [], json_encode(['code' => -9999, 'msg' => 'Rejected'], JSON_THROW_ON_ERROR)),
    );

    expect(fn () => failingApiClient($apiSystem, $failure, $path)->send())
        ->toThrow(RequestException::class, 'Vendor rejected the request');

    $log = ApiRequestLog::query()->where('path', $path)->sole();

    expect($log->http_response_code)->toBe(200)
        ->and($log->response)->toBe(['code' => -9999, 'msg' => 'Rejected'])
        ->and($log->error_message)->toBe($failure->getMessage().' (line '.$failure->getLine().')')
        ->and($log->getRawOriginal('completed_at'))->toBe(now()->format('Y-m-d H:i:s'))
        ->and($log->duration)->toBeGreaterThanOrEqual(1)
        ->and(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors(Carbon::now()->subMinute()))->toBe(1);
});

it('persists the final failure when the client-level retry also fails', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $path = '/regression/retry-failure';
    $firstFailure = new RequestException(
        'Service unavailable',
        new Request('GET', $path),
        new Response(503, [], json_encode(['msg' => 'Unavailable'], JSON_THROW_ON_ERROR)),
    );
    $finalFailure = new RequestException(
        'cURL error 56: retry connection reset',
        new Request('GET', $path),
        null,
        null,
        ['errno' => 56],
    );

    expect(fn () => sequencedApiClient($apiSystem, [$firstFailure, $finalFailure], $path)->send())
        ->toThrow(RequestException::class, 'cURL error 56');

    $log = ApiRequestLog::query()->where('path', $path)->sole();

    expect($log->http_response_code)->toBeNull()
        ->and($log->response)->toBeNull()
        ->and($log->error_message)->toBe($finalFailure->getMessage().' (line '.$finalFailure->getLine().')')
        ->and($log->getRawOriginal('completed_at'))->toBe(now()->format('Y-m-d H:i:s'))
        ->and($log->duration)->toBeGreaterThanOrEqual(1)
        ->and(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors(Carbon::now()->subMinute()))->toBe(1);
});

it('does not retain first-attempt response metadata when the retry ends with a non-http failure', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $path = '/regression/retry-runtime-failure';
    $firstFailure = new RequestException(
        'Service unavailable',
        new Request('GET', $path),
        new Response(503, [], json_encode(['msg' => 'Unavailable'], JSON_THROW_ON_ERROR)),
    );
    $finalFailure = new RuntimeException('Retry response decoding failed');

    expect(fn () => sequencedApiClient($apiSystem, [$firstFailure, $finalFailure], $path)->send())
        ->toThrow(RuntimeException::class, 'Retry response decoding failed');

    $log = ApiRequestLog::query()->where('path', $path)->sole();

    expect($log->http_response_code)->toBeNull()
        ->and($log->response)->toBeNull()
        ->and($log->http_headers_returned)->toBeNull()
        ->and($log->error_message)->toBe($finalFailure->getMessage().' (line '.$finalFailure->getLine().')')
        ->and($log->getRawOriginal('completed_at'))->toBe(now()->format('Y-m-d H:i:s'))
        ->and($log->duration)->toBeGreaterThanOrEqual(1)
        ->and(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors(Carbon::now()->subMinute()))->toBe(1);
});

it('does not retain first-attempt error metadata when the client-level retry succeeds', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $path = '/regression/retry-success';
    $firstFailure = new RequestException(
        'Service unavailable',
        new Request('GET', $path),
        new Response(503, [], json_encode(['msg' => 'Unavailable'], JSON_THROW_ON_ERROR)),
    );

    $response = sequencedApiClient(
        $apiSystem,
        [$firstFailure, new Response(200, ['X-Test' => 'recovered'], json_encode(['ok' => true], JSON_THROW_ON_ERROR))],
        $path,
    )->send();

    $log = ApiRequestLog::query()->where('path', $path)->sole();

    expect($response->getStatusCode())->toBe(200)
        ->and($log->http_response_code)->toBe(200)
        ->and($log->error_message)->toBeNull()
        ->and($log->getRawOriginal('completed_at'))->toBe(now()->format('Y-m-d H:i:s'))
        ->and($log->duration)->toBeGreaterThanOrEqual(1)
        ->and(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors(Carbon::now()->subMinute()))->toBe(0);
});

it('persists completion metadata for non-HTTP failures without changing unrelated logs', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $path = '/regression/runtime-failure';
    $failure = new RuntimeException('Response decoding failed');
    $unrelated = ApiRequestLog::factory()->successful()->create([
        'api_system_id' => $apiSystem->id,
        'path' => '/regression/unrelated',
        'duration' => 321,
    ]);

    expect(fn () => failingApiClient($apiSystem, $failure, $path)->send())
        ->toThrow(RuntimeException::class, 'Response decoding failed');

    $log = ApiRequestLog::query()->where('path', $path)->sole();
    $unrelated->refresh();

    expect($log->http_response_code)->toBeNull()
        ->and($log->error_message)->toBe($failure->getMessage().' (line '.$failure->getLine().')')
        ->and($log->getRawOriginal('completed_at'))->toBe(now()->format('Y-m-d H:i:s'))
        ->and($log->duration)->toBeGreaterThanOrEqual(1)
        ->and($unrelated->duration)->toBe(321)
        ->and($unrelated->error_message)->toBeNull();
});

it('converts Laravel HTTP fake failures to production Guzzle exceptions with response metadata', function (): void {
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'test-http-adapter',
        'name' => 'Test HTTP adapter',
        'is_exchange' => false,
    ]);
    $path = '/regression/testing-http-503';

    Http::fake(['*' => Http::response(['errors' => ['Unavailable']], 503)]);

    expect(fn (): ResponseInterface => laravelHttpBackedApiClient($apiSystem, $path)->send())
        ->toThrow(RequestException::class);

    $log = ApiRequestLog::query()->where('path', $path)->sole();

    expect($log->http_response_code)->toBe(503)
        ->and($log->response)->toBe(['errors' => ['Unavailable']])
        ->and($log->error_message)->toContain('503 Service Unavailable');

    Http::assertSentCount(1);
});

it('converts Laravel HTTP fake connection failures to production Guzzle connect exceptions', function (): void {
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'test-connect-adapter',
        'name' => 'Test connect adapter',
        'is_exchange' => false,
    ]);
    $path = '/regression/testing-connect-failure';

    Http::fake(['*' => Http::failedConnection()]);

    expect(fn (): ResponseInterface => laravelHttpBackedApiClient($apiSystem, $path)->send())
        ->toThrow(ConnectException::class);

    $log = ApiRequestLog::query()->where('path', $path)->sole();

    expect($log->http_response_code)->toBeNull()
        ->and($log->response)->toBeNull()
        ->and($log->error_message)->not->toBeNull();

    Http::assertSentCount(1);
});

it('never persists request credentials in a failed API log', function (): void {
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'credential-log-test',
        'name' => 'Credential log test',
        'is_exchange' => false,
    ]);
    $path = '/bulk?signature=path-signature&symbol=ENAUSDT';
    $properties = ApiProperties::make([
        'secret' => 'taapi-secret',
        'options' => [
            'api_key' => 'payload-api-key',
            'signature' => 'payload-signature',
            'symbol' => 'ENAUSDT',
        ],
        'construct' => ['exchange' => 'binancefutures', 'interval' => '4h'],
    ]);

    Http::fake(['*' => Http::response(['errors' => ['Unavailable']], 503)]);

    expect(fn (): ResponseInterface => sensitivePayloadApiClient($apiSystem, $path, $properties)->send())
        ->toThrow(RequestException::class);

    $log = ApiRequestLog::query()->where('api_system_id', $apiSystem->id)->sole();
    $stored = json_encode([
        'path' => $log->path,
        'payload' => $log->payload,
    ], JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain('path-signature')
        ->and($stored)->not->toContain('taapi-secret')
        ->and($stored)->not->toContain('payload-api-key')
        ->and($stored)->not->toContain('payload-signature')
        ->and($stored)->toContain('***REDACTED***')
        ->and($stored)->toContain('ENAUSDT')
        ->and($stored)->toContain('binancefutures');

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'path-signature')
        && $request->data()['secret'] === 'taapi-secret'
        && $request->data()['options']['signature'] === 'payload-signature');
});
