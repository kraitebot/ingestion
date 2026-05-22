<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler;

test('ignores regular 400 bad request errors', function (): void {
    $handler = new TaapiExceptionHandler();

    // TAAPI returns {"errors": [...]} format
    $response = new Response(
        400,
        [],
        json_encode(['errors' => ['Invalid symbol: INVALID/USDT']])
    );

    $exception = new RequestException(
        'Bad Request',
        new Request('POST', '/bulk'),
        $response
    );

    expect($handler->ignoreException($exception))->toBeTrue();
});

test('does NOT ignore 400 construct limit exceeded errors', function (): void {
    $handler = new TaapiExceptionHandler();

    // TAAPI returns this when you exceed your plan's construct limit
    $response = new Response(
        400,
        [],
        json_encode(['errors' => ['You are querying more constructs than your plan allows! You are attempting to query 20 constructs, while your plan only allows 10']])
    );

    $exception = new RequestException(
        'Bad Request',
        new Request('POST', '/bulk'),
        $response
    );

    expect($handler->ignoreException($exception))->toBeFalse();
});

test('does NOT ignore 400 calculations limit exceeded errors', function (): void {
    $handler = new TaapiExceptionHandler();

    // Alternate wording for plan limit
    $response = new Response(
        400,
        [],
        json_encode(['errors' => ['You are querying more calculations than your plan allows']])
    );

    $exception = new RequestException(
        'Bad Request',
        new Request('POST', '/bulk'),
        $response
    );

    expect($handler->ignoreException($exception))->toBeFalse();
});

test('does not ignore non-400 errors', function (): void {
    $handler = new TaapiExceptionHandler();

    $response = new Response(
        429,
        [],
        json_encode(['errors' => ['Rate limit exceeded']])
    );

    $exception = new RequestException(
        'Rate Limited',
        new Request('POST', '/bulk'),
        $response
    );

    // 429 is not in ignorable codes, so should not be ignored
    expect($handler->ignoreException($exception))->toBeFalse();
});
