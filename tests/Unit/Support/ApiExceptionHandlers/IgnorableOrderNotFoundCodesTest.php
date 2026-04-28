<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiExceptionHandlers\BitgetExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\BybitExceptionHandler;

uses()->group('unit', 'exception-handlers', 'drift');

/**
 * Helper that builds a Guzzle RequestException carrying the canonical
 * exchange response shape for an "order not found" failure.
 *
 * - $vendorBody: the JSON body the exchange would return.
 *   Bitget shape: {"code":"22001","msg":"..."}
 *   Bybit shape:  {"retCode":110001,"retMsg":"..."}
 */
function buildOrderNotFoundException(int $httpStatus, array $vendorBody, string $method = 'DELETE', string $path = '/v2/mix/order/cancel-order'): RequestException
{
    $request = new Request($method, $path);
    $response = new Response($httpStatus, [], json_encode($vendorBody));

    return new RequestException('vendor error', $request, $response);
}

it('bitget: ignoreException returns true for code 22001 (order does not exist)', function () {
    // Bitget's cancel-order endpoint returns HTTP 200 with body
    // {"code":"22001","msg":"no order to cancel"} when the targeted
    // order has already been removed (filled, manually cancelled,
    // expired). The drift spotter's idempotent path needs this
    // classified as ignorable so a stale orphan cancel completes
    // cleanly instead of looping every cycle.
    $handler = new BitgetExceptionHandler;

    $exception = buildOrderNotFoundException(200, [
        'code' => '22001',
        'msg' => 'no order to cancel',
    ]);

    expect($handler->ignoreException($exception))->toBeTrue();
});

it('bitget: ignoreException returns true for code 43001 (order does not exist)', function () {
    // Sibling Bitget code returned by certain plan-order cancel paths
    // when the target plan has already been triggered or cancelled.
    $handler = new BitgetExceptionHandler;

    $exception = buildOrderNotFoundException(200, [
        'code' => '43001',
        'msg' => 'Order does not exist',
    ]);

    expect($handler->ignoreException($exception))->toBeTrue();
});

it('bitget: ignoreException returns false for unrelated codes', function () {
    // Sanity guard: ignorable expansion must not absorb actual error
    // codes. 40808 is "Parameter verification exception" — a real
    // upstream bug we want to keep visible.
    $handler = new BitgetExceptionHandler;

    $exception = buildOrderNotFoundException(200, [
        'code' => '40808',
        'msg' => 'Parameter verification exception',
    ]);

    expect($handler->ignoreException($exception))->toBeFalse();
});

it('bybit: ignoreException returns true for retCode 110001 (order does not exist) on futures cancel', function () {
    // Bybit V5 futures cancel-of-missing-order. Spotter's idempotent
    // path mirrors Phase 2/3A's Binance behaviour: classify as
    // ignorable, mark CANCELLED locally, complete the step.
    $handler = new BybitExceptionHandler;

    $exception = buildOrderNotFoundException(200, [
        'retCode' => 110001,
        'retMsg' => 'order does not exists or too late to cancel',
    ]);

    expect($handler->ignoreException($exception))->toBeTrue();
});

it('bybit: 170213 stays retryable (eventual-consistency lag on spot place), NOT ignorable', function () {
    // Documented blast-radius limit: 170213 on Bybit spot is more
    // commonly the "order isn't visible yet right after a place"
    // signal than a true missing order. Promoting it to ignorable
    // would silently swallow legitimate retry-worthy cases. Spotter's
    // futures-cancel idempotency is fully covered by 110001 above;
    // 170213 stays in retryableHttpCodes only.
    $handler = new BybitExceptionHandler;

    $exception = buildOrderNotFoundException(200, [
        'retCode' => 170213,
        'retMsg' => 'Order does not exist',
    ]);

    expect($handler->ignoreException($exception))->toBeFalse();
    expect($handler->retryException($exception))->toBeTrue();
});

it('bybit: ignoreException returns false for unrelated retCodes', function () {
    // A signature mismatch must not be silently swallowed.
    $handler = new BybitExceptionHandler;

    $exception = buildOrderNotFoundException(200, [
        'retCode' => 10004,
        'retMsg' => 'Invalid signature',
    ]);

    expect($handler->ignoreException($exception))->toBeFalse();
});
