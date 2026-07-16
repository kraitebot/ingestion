<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;

it('resolves supported Bitget futures quotes without fallback', function (string $quote, string $productType): void {
    $context = BitgetProductContext::fromQuote($quote);

    expect($context->quote)->toBe(mb_strtoupper($quote))
        ->and($context->productType)->toBe($productType)
        ->and($context->marginCoin)->toBe(mb_strtoupper($quote));
})->with([
    'USDT' => ['USDT', 'USDT-FUTURES'],
    'USDC' => ['usdc', 'USDC-FUTURES'],
]);

it('lists exactly the two supported Bitget futures quotes', function (): void {
    expect(BitgetProductContext::supportedQuotes())->toBe(['USDT', 'USDC']);
});

it('rejects missing and unsupported Bitget futures quotes explicitly', function (?string $quote, string $display): void {
    expect(fn (): BitgetProductContext => BitgetProductContext::fromQuote($quote))
        ->toThrow(
            InvalidArgumentException::class,
            "Unsupported Bitget futures quote [{$display}]. Supported quotes: USDT, USDC."
        );
})->with([
    'null' => [null, 'null'],
    'empty' => ['', 'empty'],
    'whitespace' => ['   ', 'empty'],
    'coin futures are not inferred' => ['BTC', 'BTC'],
    'other fiat stablecoin' => ['EURC', 'EURC'],
]);
