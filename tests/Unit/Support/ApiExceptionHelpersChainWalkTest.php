<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;

/**
 * Pins the behaviour that the ignore / retry / classify chain walks the
 * exception's $previous chain to find the underlying RequestException.
 *
 * Background: our atomic jobs occasionally wrap Guzzle ClientExceptions
 * in RuntimeException so the error message can include diagnostic
 * context (e.g. "apiModify failed for order #X — actual price after
 * sync=Y"). Before this contract was pinned, the wrap silently hid the
 * vendor code from the classifier — ignoreException(wrapped) returned
 * false for a wrapped -5027, so what Binance was signalling as "no-op
 * success" got routed to the generic fail path and marked the step
 * Failed. The WAP self-dispatched follow-up (which always hits -5027
 * because the prior WAP already landed our reference values) would
 * then flash a spurious Failed in the step graph every cycle.
 */
function buildBinanceClientException(int $statusCode, ?int $vendorCode, string $message): ClientException
{
    $body = $vendorCode === null
        ? '{"msg":"'.$message.'"}'
        : '{"code":'.$vendorCode.',"msg":"'.$message.'"}';

    $request = new Request('PUT', 'https://fapi.binance.com/fapi/v1/order');
    $response = new Response($statusCode, [], $body);

    return new ClientException(
        message: "Client error: {$statusCode} {$message}",
        request: $request,
        response: $response,
    );
}

it('classifies a raw 400/-5027 ClientException as ignorable (baseline)', function (): void {
    $exception = buildBinanceClientException(400, -5027, 'No need to modify the order.');

    $handler = new BinanceExceptionHandler;

    expect($handler->ignoreException($exception))->toBeTrue();
});

it('classifies a RuntimeException that wraps a 400/-5027 ClientException as ignorable (chain walk)', function (): void {
    $root = buildBinanceClientException(400, -5027, 'No need to modify the order.');

    $wrapped = new RuntimeException(
        'apiModify failed for profit order #42 — diagnostic details...',
        0,
        $root,
    );

    $handler = new BinanceExceptionHandler;

    expect($handler->ignoreException($wrapped))->toBeTrue();
});

it('walks several previous levels deep before giving up', function (): void {
    $root = buildBinanceClientException(400, -5027, 'No need to modify the order.');

    $lvl1 = new RuntimeException('lvl1', 0, $root);
    $lvl2 = new DomainException('lvl2', 0, $lvl1);
    $lvl3 = new LogicException('lvl3', 0, $lvl2);

    $handler = new BinanceExceptionHandler;

    expect($handler->ignoreException($lvl3))->toBeTrue();
});

it('does NOT classify a wrapped non-ignorable vendor code as ignorable', function (): void {
    // 400 with some unrelated vendor code Binance might return on a real
    // failure — the classifier must not swallow it just because we wrapped.
    $root = buildBinanceClientException(400, -9999, 'Some fatal reason.');

    $wrapped = new RuntimeException('wrapped failure', 0, $root);

    $handler = new BinanceExceptionHandler;

    expect($handler->ignoreException($wrapped))->toBeFalse();
});

it('returns false for a plain RuntimeException with no RequestException anywhere in the chain', function (): void {
    $exception = new RuntimeException('something unrelated exploded');

    $handler = new BinanceExceptionHandler;

    expect($handler->ignoreException($exception))->toBeFalse();
});

it('classifies 503 server-instability code correctly even when wrapped', function (): void {
    // 503 is on the flat `retryableHttpCodes` list — same chain-walk applies.
    $root = buildBinanceClientException(503, null, 'Service Unavailable');

    $wrapped = new RuntimeException('wrapped', 0, $root);

    $handler = new BinanceExceptionHandler;

    expect($handler->retryException($wrapped))->toBeTrue();
});

it('correctly routes retry for wrapped 400/-1021 recvWindow mismatch', function (): void {
    $root = buildBinanceClientException(400, -1021, 'Timestamp for this request is outside of the recvWindow.');

    $wrapped = new RuntimeException('wrapped recv-window error', 0, $root);

    $handler = new BinanceExceptionHandler;

    expect($handler->retryException($wrapped))->toBeTrue();
    expect($handler->ignoreException($wrapped))->toBeFalse();
});
