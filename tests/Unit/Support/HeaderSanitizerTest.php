<?php

declare(strict_types=1);

use Kraite\Core\Support\HeaderSanitizer;

/**
 * Regression guard for credential leakage via `api_request_logs.http_headers_sent`.
 *
 * Auth headers (`ACCESS-KEY`, `ACCESS-PASSPHRASE`, `ACCESS-SIGN`,
 * `X-MBX-APIKEY`, `KC-API-KEY`, `KC-API-PASSPHRASE`, `X-BAPI-API-KEY`,
 * `X-BAPI-SIGN`, etc.) MUST be redacted before the log row is written —
 * they're full credentials in plaintext, identical security risk to the
 * model attributes already scrubbed via `$hidden` (Layer A).
 *
 * Non-sensitive headers (`Content-Type`, `Accept`, `*-TIMESTAMP`) MUST
 * pass through unchanged so debugging request shapes still works.
 */
const SENSITIVE_HEADERS_REDACTED_TO = '***REDACTED***';

it('redacts Bitget auth headers (ACCESS-KEY, ACCESS-SIGN, ACCESS-PASSPHRASE)', function (): void {
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'ACCESS-KEY' => 'bg_1c0670ac6b7f4b288c6c61d9af1a5793',
        'ACCESS-SIGN' => '12hGD6FamXN8rTcvLT+04Nm3yy/HFm29QRuxycDlIyM=',
        'ACCESS-TIMESTAMP' => '1777232893191',
        'ACCESS-PASSPHRASE' => 'MoraisSoares1',
    ];

    $sanitized = HeaderSanitizer::sanitize($headers);

    expect($sanitized['ACCESS-KEY'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['ACCESS-SIGN'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['ACCESS-PASSPHRASE'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);

    // Timestamp stays — useful for replay debugging, not a credential.
    expect($sanitized['ACCESS-TIMESTAMP'])->toBe('1777232893191');

    // Non-auth headers pass through unchanged.
    expect($sanitized['Content-Type'])->toBe('application/json');
    expect($sanitized['Accept'])->toBe('application/json');
});

it('redacts Binance X-MBX-APIKEY header', function (): void {
    $headers = ['X-MBX-APIKEY' => 'binance-key-leak'];

    expect(HeaderSanitizer::sanitize($headers)['X-MBX-APIKEY'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
});

it('redacts KuCoin auth headers (KC-API-KEY, KC-API-SIGN, KC-API-PASSPHRASE)', function (): void {
    $headers = [
        'KC-API-KEY' => 'kucoin-key-leak',
        'KC-API-SIGN' => 'kucoin-sig-leak',
        'KC-API-PASSPHRASE' => 'kucoin-pass-leak',
        'KC-API-TIMESTAMP' => '1777232893191',
    ];

    $sanitized = HeaderSanitizer::sanitize($headers);

    expect($sanitized['KC-API-KEY'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['KC-API-SIGN'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['KC-API-PASSPHRASE'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['KC-API-TIMESTAMP'])->toBe('1777232893191');
});

it('redacts Bybit auth headers (X-BAPI-API-KEY, X-BAPI-SIGN)', function (): void {
    $headers = [
        'X-BAPI-API-KEY' => 'bybit-key-leak',
        'X-BAPI-SIGN' => 'bybit-sig-leak',
        'X-BAPI-TIMESTAMP' => '1777232893191',
    ];

    $sanitized = HeaderSanitizer::sanitize($headers);

    expect($sanitized['X-BAPI-API-KEY'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['X-BAPI-SIGN'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['X-BAPI-TIMESTAMP'])->toBe('1777232893191');
});

it('redacts the generic Authorization header', function (): void {
    $headers = ['Authorization' => 'Bearer secret-token-xyz'];

    expect(HeaderSanitizer::sanitize($headers)['Authorization'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
});

it('matches sensitive header keys case-insensitively', function (): void {
    $headers = [
        'access-key' => 'lowercase-leak',
        'Access-Passphrase' => 'titlecase-leak',
        'X-MBX-ApiKey' => 'mixed-case-leak',
    ];

    $sanitized = HeaderSanitizer::sanitize($headers);

    expect($sanitized['access-key'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['Access-Passphrase'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['X-MBX-ApiKey'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
});

it('preserves the original key casing on output', function (): void {
    $headers = ['ACCESS-KEY' => 'leak'];

    $sanitized = HeaderSanitizer::sanitize($headers);

    expect(array_key_exists('ACCESS-KEY', $sanitized))->toBeTrue(
        'Sanitizer must preserve the original header key as-is — '
        .'downstream debugging tooling may key off it.'
    );
});

it('returns an empty array unchanged', function (): void {
    expect(HeaderSanitizer::sanitize([]))->toBe([]);
});

it('handles arrays of header values (Guzzle multi-header format)', function (): void {
    // Some HTTP clients return headers as arrays of strings to support
    // repeated headers. The sanitizer must redact whichever the value
    // shape is.
    $headers = [
        'ACCESS-KEY' => ['bg_1c067-multi-leak'],
        'Content-Type' => ['application/json'],
    ];

    $sanitized = HeaderSanitizer::sanitize($headers);

    expect($sanitized['ACCESS-KEY'])->toBe(SENSITIVE_HEADERS_REDACTED_TO);
    expect($sanitized['Content-Type'])->toBe(['application/json']);
});
