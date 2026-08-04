<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiLogSanitizer;

mutates(ApiLogSanitizer::class);

it('recursively redacts credentials while preserving trading payload fields', function (): void {
    $payload = [
        'secret' => 'taapi-secret-value',
        'options' => [
            'signature' => 'binance-signature-value',
            'api_key' => 'provider-key-value',
            'symbol' => 'BTCUSDT',
            'interval' => '4h',
        ],
        'construct' => [
            'token' => 'ENA',
            'indicators' => [['id' => 'rsi', 'backtrack' => 0]],
        ],
    ];

    $sanitized = ApiLogSanitizer::payload($payload);

    expect($sanitized['secret'])->toBe(ApiLogSanitizer::REDACTION_PLACEHOLDER)
        ->and($sanitized['options']['signature'])->toBe(ApiLogSanitizer::REDACTION_PLACEHOLDER)
        ->and($sanitized['options']['api_key'])->toBe(ApiLogSanitizer::REDACTION_PLACEHOLDER)
        ->and($sanitized['options']['symbol'])->toBe('BTCUSDT')
        ->and($sanitized['construct']['token'])->toBe('ENA')
        ->and($sanitized['construct']['indicators'][0])->toBe(['id' => 'rsi', 'backtrack' => 0]);
});

it('redacts sensitive query values from logged paths only', function (): void {
    $path = '/fapi/v1/order?symbol=BTCUSDT&timestamp=123&signature=live-signature&api_key=live-key';

    $sanitized = ApiLogSanitizer::path($path);

    expect($sanitized)->toContain('/fapi/v1/order?')
        ->and($sanitized)->toContain('symbol=BTCUSDT')
        ->and($sanitized)->toContain('timestamp=123')
        ->and($sanitized)->not->toContain('live-signature')
        ->and($sanitized)->not->toContain('live-key')
        ->and(urldecode($sanitized))->toContain('signature='.ApiLogSanitizer::REDACTION_PLACEHOLDER)
        ->and(urldecode($sanitized))->toContain('api_key='.ApiLogSanitizer::REDACTION_PLACEHOLDER);
});
