<?php

declare(strict_types=1);

use Kraite\Core\Trading\Kraite;

/**
 * Pin the two computation helpers — small but load-bearing.
 *
 *   - returnLadderedValue clamps the rung index to the last
 *     available multiplier. The ladder calculator iterates 1..N
 *     against potentially shorter multiplier arrays; without the
 *     clamp, rung 5 against [2,2,2,2] would be a null array
 *     access, throwing on every position with N>4.
 *
 *   - pctToDecimal converts the operator's percent input ("0.36")
 *     into the decimal the math layer expects ("0.0036"). A
 *     regression that drops the /100 ships as TPs placed 100x
 *     further than configured — never fillable.
 */
it('returnLadderedValue returns the indexed value when in range', function (): void {
    expect(Kraite::returnLadderedValue([2, 3, 4, 5], 0))->toBe(2)
        ->and(Kraite::returnLadderedValue([2, 3, 4, 5], 2))->toBe(4);
});

it('returnLadderedValue clamps the index to the LAST element when out of range', function (): void {
    // Ladder N=5 against multipliers=[2,2,2,2] — rung index 5 must
    // resolve to multiplier[3] (last), not throw.
    expect(Kraite::returnLadderedValue([2, 2, 2, 2], 4))->toBe(2)
        ->and(Kraite::returnLadderedValue([2, 2, 2, 2], 99))->toBe(2);
});

it('returnLadderedValue clamps a negative index to 0 (defensive)', function (): void {
    expect(Kraite::returnLadderedValue([10, 20, 30], -5))->toBe(10);
});

it('returnLadderedValue throws on an empty multiplier array', function (): void {
    Kraite::returnLadderedValue([], 0);
})->throws(InvalidArgumentException::class);

it('pctToDecimal divides percent by 100 (0.36% → "0.0036")', function (): void {
    $result = Kraite::pctToDecimal('0.36', 'tp');

    expect((float) $result)->toBe(0.0036);
});

it('pctToDecimal handles whole-number percents (5% → 0.05)', function (): void {
    $result = Kraite::pctToDecimal('5', 'sl');

    expect((float) $result)->toBe(0.05);
});

it('pctToDecimal accepts zero', function (): void {
    $result = Kraite::pctToDecimal('0', 'tp');

    expect((float) $result)->toBe(0.0);
});

it('pctToDecimal throws InvalidArgumentException for non-numeric input', function (): void {
    Kraite::pctToDecimal('not-a-number', 'tp');
})->throws(InvalidArgumentException::class);

it('pctToDecimal throws InvalidArgumentException for negative percent', function (): void {
    Kraite::pctToDecimal('-1', 'tp');
})->throws(InvalidArgumentException::class);

it('pctToDecimal includes the label in the exception message (operator-helpful)', function (): void {
    try {
        Kraite::pctToDecimal('not-a-number', 'profit_percentage');
        $this->fail('Expected exception');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('profit_percentage');
    }
});
