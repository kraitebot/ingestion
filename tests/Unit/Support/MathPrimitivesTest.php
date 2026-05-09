<?php

declare(strict_types=1);

use Kraite\Core\Support\Math;

/**
 * Pin Math primitives — these underwrite every gate, drift detector,
 * and notional check in the codebase. Decimal columns come back as
 * strings ('0.500' vs '0.50') and `===` betrays them; Math::equal
 * is the precision-safe equality that keeps observers from emitting
 * false-drift signals on every save.
 *
 * A regression here fans out everywhere: drift loops (CorrectModified
 * vs reference), TP fill detection, min-notional checks, ladder
 * monotonicity. Pin them.
 */
it('equal: treats string forms of the same value as equal', function (): void {
    expect(Math::equal('0.10', '0.1'))->toBeTrue()
        ->and(Math::equal('0.500', '0.5'))->toBeTrue()
        ->and(Math::equal('100', '100.000'))->toBeTrue();
});

it('equal: distinguishes genuinely different values', function (): void {
    expect(Math::equal('0.1', '0.2'))->toBeFalse()
        ->and(Math::equal('100', '99.99999999'))->toBeFalse();
});

it('equal: int/float/string interop returns true for the same number', function (): void {
    expect(Math::equal(100, '100'))->toBeTrue()
        ->and(Math::equal('0.5', '0.50'))->toBeTrue()
        ->and(Math::equal(0.5, 0.5))->toBeTrue();
});

it('gt / lt are strict and not equal at the boundary', function (): void {
    expect(Math::gt('1', '1'))->toBeFalse()
        ->and(Math::lt('1', '1'))->toBeFalse()
        ->and(Math::gt('1.001', '1'))->toBeTrue()
        ->and(Math::lt('0.999', '1'))->toBeTrue();
});

it('gte / lte include the boundary', function (): void {
    expect(Math::gte('1', '1'))->toBeTrue()
        ->and(Math::lte('1', '1'))->toBeTrue();
});

it('cmp returns -1 / 0 / 1 (the spaceship contract)', function (): void {
    expect(Math::cmp('1', '2'))->toBe(-1)
        ->and(Math::cmp('2', '1'))->toBe(1)
        ->and(Math::cmp('1', '1'))->toBe(0);
});

it('add: precision is preserved at default scale (no float drift)', function (): void {
    // 0.1 + 0.2 in float = 0.30000000000000004; Math::add returns a clean
    // decimal string. This is the whole reason the helper exists.
    $sum = Math::add('0.1', '0.2');

    expect((float) $sum)->toBe(0.3);
});

it('sub: precision is preserved at default scale', function (): void {
    expect((float) Math::sub('0.30', '0.10'))->toBe(0.20);
});

it('mul: applies the scale parameter (truncate, not round)', function (): void {
    expect(Math::mul('1', '3', 0))->toBe('3')
        ->and(Math::mul('0.123456789', '1', 4))->toBe('0.1234');
});

it('div by 3 at scale 8 produces 0.33333333 (BCMath truncation)', function (): void {
    expect(Math::div('1', '3', 8))->toBe('0.33333333');
});

it('pow handles integer exponents', function (): void {
    expect((int) Math::pow('2', 4))->toBe(16);
});

it('isPositive: positive numbers true, zero/negative false', function (): void {
    expect(Math::isPositive('1'))->toBeTrue()
        ->and(Math::isPositive('0.0001'))->toBeTrue()
        ->and(Math::isPositive('0'))->toBeFalse()
        ->and(Math::isPositive('-1'))->toBeFalse();
});

it('add accepts comma-decimal strings via normalisation', function (): void {
    // Normalised by toDecimalString — a regression that drops comma
    // handling ships as InvalidArgumentException on every European
    // accounting input passed to a config-driven helper.
    expect((float) Math::add('1,5', '0,5'))->toBe(2.0);
});

it('mul accepts scientific-notation strings', function (): void {
    expect((float) Math::mul('1e-2', '100'))->toBe(1.0);
});
