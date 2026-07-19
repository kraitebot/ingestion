<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiExceptionHandlers\BitgetExceptionHandler;
use Tests\Support\ResponseException;

uses()->group('unit', 'bitget', 'exception-handler');

it('classifies current Bitget invalid-IP codes as whitelist failures', function (string $code): void {
    $handler = new BitgetExceptionHandler;
    $exception = ResponseException::bitget(200, $code, 'Current Bitget invalid IP response');

    expect($handler->isIpNotWhitelisted($exception))->toBeTrue()
        ->and($handler->isAccountBlocked($exception))->toBeFalse();
})->with(['40018', '40038']);

it('classifies current Bitget credential and permission codes as account failures', function (string $code): void {
    $handler = new BitgetExceptionHandler;
    $exception = ResponseException::bitget(200, $code, 'Current Bitget credential response');

    expect($handler->isAccountBlocked($exception))->toBeTrue()
        ->and($handler->isIpNotWhitelisted($exception))->toBeFalse();
})->with([
    '40006',
    '40009',
    '40011',
    '40012',
    '40014',
    '40025',
    '40036',
    '40037',
    '40040',
    '40041',
]);

it('does not misclassify generic parameter verification as a credential failure', function (): void {
    $handler = new BitgetExceptionHandler;
    $exception = ResponseException::bitget(200, '40017', 'Parameter verification failed');

    expect($handler->isAccountBlocked($exception))->toBeFalse()
        ->and($handler->isIpNotWhitelisted($exception))->toBeFalse();
});

it('classifies Bitget vendor codes independently of the HTTP transport status', function (
    string $code,
    string $classification,
): void {
    $handler = new BitgetExceptionHandler;
    $exception = ResponseException::bitget(400, $code, 'Bitget transport error');

    expect($handler->{$classification}($exception))->toBeTrue();
})->with([
    'invalid IP' => ['40038', 'isIpNotWhitelisted'],
    'invalid credential' => ['40014', 'isAccountBlocked'],
]);

it('uses the documented five-minute recovery when 429 has no Retry-After header', function (): void {
    $handler = new BitgetExceptionHandler;

    expect(now()->diffInSeconds($handler->rateLimitUntil(ResponseException::bitgetIpRateLimited()), false))
        ->toBe(300.0);
});
