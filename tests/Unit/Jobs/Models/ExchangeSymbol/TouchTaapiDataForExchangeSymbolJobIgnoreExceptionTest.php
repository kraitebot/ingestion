<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kraite\Core\Jobs\Models\ExchangeSymbol\TouchTaapiDataForExchangeSymbolJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;

function makeTaapiTouchJob(): array
{
    $binance = ApiSystem::factory()->exchange()->create([
        'name' => 'Binance',
        'canonical' => 'binance',
    ]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'DATAIP',
        'quote' => 'USDC',
        'api_statuses' => [],
    ]);

    return [new TouchTaapiDataForExchangeSymbolJob($exchangeSymbol->id), $exchangeSymbol];
}

function makeTaapiRequestException(int $status, string $body): RequestException
{
    return new RequestException(
        'Client error',
        new Request('GET', '/candles'),
        new Response($status, [], $body)
    );
}

test('ignores 404 no-candle-data response and marks symbol verified without data', function (): void {
    [$job, $exchangeSymbol] = makeTaapiTouchJob();

    // TAAPI's 404 shape for unsupported pairs (e.g. new non-USDT quotes)
    $exception = makeTaapiRequestException(404, json_encode(['data' => 'No candle data found for interval 1h']));

    expect($job->ignoreException($exception))->toBeTrue();

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->api_statuses['taapi_verified'])->toBeTrue()
        ->and($exchangeSymbol->api_statuses['has_taapi_data'])->toBeFalse();
});

test('does NOT ignore 404 with an unrelated body', function (): void {
    [$job, $exchangeSymbol] = makeTaapiTouchJob();

    $exception = makeTaapiRequestException(404, json_encode(['data' => 'Route not found']));

    expect($job->ignoreException($exception))->toBeFalse();

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->api_statuses['taapi_verified'])->toBeFalse();
});

test('still ignores 400 no-candles response and marks symbol verified without data', function (): void {
    [$job, $exchangeSymbol] = makeTaapiTouchJob();

    $exception = makeTaapiRequestException(400, json_encode(['errors' => ['No candles available for this symbol']]));

    expect($job->ignoreException($exception))->toBeTrue();

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->api_statuses['taapi_verified'])->toBeTrue()
        ->and($exchangeSymbol->api_statuses['has_taapi_data'])->toBeFalse();
});

test('does NOT ignore other status codes', function (): void {
    [$job] = makeTaapiTouchJob();

    $exception = makeTaapiRequestException(429, json_encode(['errors' => ['Rate limit exceeded']]));

    expect($job->ignoreException($exception))->toBeFalse();
});
