<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Trading\Kraite;

function buildProtectionSymbol(): ExchangeSymbol
{
    $symbol = new ExchangeSymbol;
    $symbol->token = 'PROTECTION';
    $symbol->quote = 'USDT';
    $symbol->min_price = '0.01';
    $symbol->max_price = '1000';
    $symbol->tick_size = '0.01';
    $symbol->price_precision = 2;
    $symbol->quantity_precision = 3;

    return $symbol;
}

it('rejects a non-positive LONG stop price before the symbol minimum can hide it', function (string $stopPercentage): void {
    expect(fn () => Kraite::calculateStopLossOrder(
        direction: 'LONG',
        anchorPrice: '100',
        stopPercent: $stopPercentage,
        currentQty: '1',
        exchangeSymbol: buildProtectionSymbol(),
    ))->toThrow(RuntimeException::class, 'Computed stop price <= 0');
})->with([
    'exactly one hundred percent' => ['100'],
    'above one hundred percent' => ['150'],
]);

it('still clamps a positive stop price that only falls below the symbol minimum', function (): void {
    $symbol = buildProtectionSymbol();
    $symbol->min_price = '10';

    $stop = Kraite::calculateStopLossOrder(
        direction: 'LONG',
        anchorPrice: '100',
        stopPercent: '95',
        currentQty: '1',
        exchangeSymbol: $symbol,
    );

    expect($stop['price'])->toBe('10');
});
