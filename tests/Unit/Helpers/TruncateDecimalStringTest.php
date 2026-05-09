<?php

declare(strict_types=1);

/**
 * Pin truncate_decimal_string + remove_trailing_zeros against the
 * TAKE #151 / TAKE #146 / TURTLE #149 incident class — integer
 * quantities like "1310" or "460" being divided by 10× because the
 * naive `rtrim('0')` swept the significant digits along with the
 * trailing zeros. The fix lives in the `str_contains($truncated,
 * '.')` guard: only the fraction may have its zeros stripped, never
 * the integer part.
 *
 * A regression that drops the guard ships as ladder rejections
 * across every integer-quantity symbol — exactly the rung-rejection
 * cascade Bruno burned 4 hours debugging in March.
 */
it('preserves integer quantities (no rtrim 0 sweep on pure integers)', function (): void {
    expect(truncate_decimal_string('1310', 8))->toBe('1310')
        ->and(truncate_decimal_string('460', 8))->toBe('460')
        ->and(truncate_decimal_string('100', 0))->toBe('100');
});

it('strips trailing zeros only when there is a fractional part', function (): void {
    expect(truncate_decimal_string('1.50', 4))->toBe('1.5')
        ->and(truncate_decimal_string('0.10', 4))->toBe('0.1')
        ->and(truncate_decimal_string('1.000', 4))->toBe('1');
});

it('truncates to the requested precision (no rounding, just slice)', function (): void {
    expect(truncate_decimal_string('0.123456789', 4))->toBe('0.1234')
        ->and(truncate_decimal_string('0.999999', 2))->toBe('0.99');
});

it('handles a precision of 0 (drops the fraction entirely)', function (): void {
    expect(truncate_decimal_string('100.999', 0))->toBe('100');
});

it('returns "0" instead of "-0" for the negative-zero edge case', function (): void {
    expect(truncate_decimal_string('-0.0000', 4))->toBe('0');
});

it('preserves the negative sign for actual negative numbers', function (): void {
    expect(truncate_decimal_string('-1.50', 4))->toBe('-1.5');
});

it('returns "0" for an empty string', function (): void {
    expect(truncate_decimal_string('', 8))->toBe('0');
});

it('handles a leading dot (".5" → "0.5", treats integer part as 0)', function (): void {
    expect(truncate_decimal_string('.5', 4))->toBe('0.5');
});

it('remove_trailing_zeros: integer survives, fraction zero-strip applies', function (): void {
    expect(remove_trailing_zeros('1310'))->toBe('1310')
        ->and(remove_trailing_zeros('1.50'))->toBe('1.5')
        ->and(remove_trailing_zeros('0.0001'))->toBe('0.0001');
});

it('remove_trailing_zeros: cleans the canonical "looks integer but stored as decimal" rows', function (): void {
    // Decimal columns come back as '100.00000000' from the driver. The
    // helper must collapse them to '100' so the downstream API sees
    // the clean integer.
    expect(remove_trailing_zeros('100.00000000'))->toBe('100');
});
