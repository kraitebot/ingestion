<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\ExchangeSymbol\ConfirmPriceAlignmentWithDirectionJob;
use Kraite\Core\Models\ExchangeSymbol;

it('formats quantity without float precision loss for large decimal strings', function (): void {
    $exchangeSymbol = new ExchangeSymbol;
    $exchangeSymbol->quantity_precision = 8;

    $formatted = api_format_quantity('12345678901234567890.123456789123456789', $exchangeSymbol);

    expect($formatted)->toBe('12345678901234567890.12345678');
});

it('formats price without float precision loss for large decimal strings', function (): void {
    $exchangeSymbol = new ExchangeSymbol;
    $exchangeSymbol->price_precision = 8;
    $exchangeSymbol->tick_size = '0.00000001';

    $formatted = api_format_price('12345678901234567890.123456789123456789', $exchangeSymbol);

    expect($formatted)->toBe('12345678901234567890.12345678');
});

it('uses the current conclude direction job namespace in alignment confirmation flow', function (): void {
    $reflection = new ReflectionClass(ConfirmPriceAlignmentWithDirectionJob::class);
    $sourcePath = $reflection->getFileName();
    $source = file_get_contents($sourcePath);

    expect($source)->toContain('ConcludeSymbolDirectionAtTimeframeJob::class')
        ->and($source)->not->toContain('\\Kraite\\Core\\_Jobs\\Models\\ExchangeSymbol\\ConcludeSymbolDirectionAtTimeframeJob::class');
});
