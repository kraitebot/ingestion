<?php

declare(strict_types=1);

use Kraite\Core\Support\PositionClosingOrderSemantics;

dataset('closing order semantics', [
    'one-way LONG reduce-only SELL closes' => [false, 'LONG', 'SELL', 'BOTH', true, false, true],
    'one-way SHORT close-position BUY closes' => [false, 'SHORT', 'BUY', 'BOTH', false, true, true],
    'one-way closing side without a closing flag is ambiguous' => [false, 'LONG', 'SELL', 'BOTH', false, false, false],
    'one-way opening side never closes' => [false, 'LONG', 'BUY', 'BOTH', true, false, false],
    'one-way explicit opposite hedge slot is rejected' => [false, 'LONG', 'SELL', 'SHORT', true, false, false],
    'hedge LONG SELL closes without reduce-only' => [true, 'LONG', 'SELL', 'LONG', false, false, true],
    'hedge SHORT BUY closes without reduce-only' => [true, 'SHORT', 'BUY', 'SHORT', false, false, true],
    'hedge wrong side never closes' => [true, 'LONG', 'BUY', 'LONG', false, false, false],
    'hedge wrong position side never closes' => [true, 'LONG', 'SELL', 'SHORT', false, false, false],
    'hedge missing position side is ambiguous' => [true, 'LONG', 'SELL', null, false, false, false],
]);

it('recognises closing intent using the exchange position mode', function (
    bool $hedgeMode,
    string $direction,
    ?string $side,
    ?string $positionSide,
    ?bool $reduceOnly,
    ?bool $closePosition,
    bool $expected,
): void {
    $semantics = new PositionClosingOrderSemantics;

    expect($semantics->matches(
        hedgeMode: $hedgeMode,
        direction: $direction,
        side: $side,
        positionSide: $positionSide,
        reduceOnly: $reduceOnly,
        closePosition: $closePosition,
    ))->toBe($expected);
})->with('closing order semantics');
