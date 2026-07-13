<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ValueObjects\ApiResponse;

/**
 * ApiResponse must be constructible with a NULL response — that is the
 * "nothing to close" signal apiClose() returns when a position never
 * reached the exchange (buildCloseOrderAttributes() === null).
 *
 * Position #736 (LTCUSDT, 2026-07-13) crashed the cancel/rollback
 * workflow with "Cannot assign null to property ApiResponse::$response of
 * type GuzzleHttp\Psr7\Response" because the property was typed
 * non-nullable while the constructor is designed to accept null. The open
 * had already been refused (market slice below the $20 min notional), so
 * there was nothing on the exchange to close — the empty-response path was
 * exactly the one that could never be constructed.
 */
it('constructs with a null response — the nothing-to-close path', function (): void {
    $api = new ApiResponse;

    expect($api->response)->toBeNull()
        ->and($api->result)->toBe([]);
});

it('preserves the result payload when the response is null', function (): void {
    $api = new ApiResponse(null, ['already_closed' => true]);

    expect($api->response)->toBeNull()
        ->and($api->result)->toBe(['already_closed' => true]);
});

it('still accepts a real Guzzle response', function (): void {
    $response = new Response(200, [], 'ok');

    $api = new ApiResponse($response, ['x' => 1]);

    expect($api->response)->toBe($response)
        ->and($api->result)->toBe(['x' => 1]);
});
