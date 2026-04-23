<?php

declare(strict_types=1);

/**
 * Regression coverage for truncate_decimal_string().
 *
 * Bug caught live on 2026-04-23 via TAKE #151 ladder rejection.
 * The helper was stripping trailing zeros unconditionally — including
 * on INTEGER inputs where the zero is significant. "1310" with
 * precision 0 collapsed to "131", silently dividing the rung qty by
 * 10 and producing a below-min-notional projection that aborted the
 * open. Same mechanism behind TAKE #146, TURTLE #149, and likely
 * USELESS #64.
 *
 * The trailing-zero strip is only correct when there IS a fractional
 * part. These tests pin:
 *   - integer values with trailing zeros MUST keep them
 *   - fractional values with trailing zeros MUST still get trimmed
 *   - existing negative-zero and empty-string edge cases stay stable
 */
it('preserves trailing zeros on integer inputs (the TAKE #151 bug)', function (): void {
    expect(truncate_decimal_string('1310', 0))->toBe('1310');
    expect(truncate_decimal_string('460', 0))->toBe('460');
    expect(truncate_decimal_string('100', 0))->toBe('100');
    expect(truncate_decimal_string('2000', 0))->toBe('2000');
    expect(truncate_decimal_string('10', 0))->toBe('10');
});

it('preserves trailing zeros when precision is zero and input has no fraction', function (): void {
    // Quantity-precision-zero markets (e.g. TAKEUSDT with qty_precision = 0)
    // must round-trip integers untouched. This is the exact signature of
    // the production bug.
    expect(truncate_decimal_string('655', 0))->toBe('655');
    expect(truncate_decimal_string('1310', 0))->toBe('1310');
});

it('still trims trailing zeros in the fractional part', function (): void {
    expect(truncate_decimal_string('0.04560', 8))->toBe('0.0456');
    expect(truncate_decimal_string('1.2300', 4))->toBe('1.23');
    expect(truncate_decimal_string('10.500', 3))->toBe('10.5');
});

it('truncates the fractional part to the requested precision', function (): void {
    expect(truncate_decimal_string('0.123456789', 4))->toBe('0.1234');
    expect(truncate_decimal_string('1.99999', 2))->toBe('1.99');
});

it('collapses pure-zero fractional tails to the integer part only', function (): void {
    expect(truncate_decimal_string('5.0000', 4))->toBe('5');
    expect(truncate_decimal_string('100.00', 2))->toBe('100');
});

it('handles negative values without emitting "-0"', function (): void {
    expect(truncate_decimal_string('-0.0000', 4))->toBe('0');
    expect(truncate_decimal_string('-1310', 0))->toBe('-1310');
    expect(truncate_decimal_string('-1.2300', 4))->toBe('-1.23');
});

it('handles empty integer part (".5" style input)', function (): void {
    expect(truncate_decimal_string('.5', 4))->toBe('0.5');
    expect(truncate_decimal_string('.00', 2))->toBe('0');
});
