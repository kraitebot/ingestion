<?php

declare(strict_types=1);

use Kraite\Core\Support\TpSlResolver;

/**
 * Resolution rule (applied independently for TP and SL):
 *
 *   if symbol value IS NULL          → use account value (override flag IGNORED)
 *   elseif account override === true → use account value
 *   else                             → use symbol value
 *
 * Symbol-NULL always wins fallback. Override only flips when symbol HAS a value.
 */
it('falls back to account value when symbol value is null and override is false', function (): void {
    $resolved = TpSlResolver::resolve(symbolValue: null, accountOverride: false, accountValue: '0.360');

    expect($resolved)->toBe('0.360');
});

it('falls back to account value when symbol value is null and override is true', function (): void {
    // Symbol-NULL beats override flag — fallback is unconditional when no symbol value exists.
    $resolved = TpSlResolver::resolve(symbolValue: null, accountOverride: true, accountValue: '0.360');

    expect($resolved)->toBe('0.360');
});

it('uses symbol value when set and override is false', function (): void {
    $resolved = TpSlResolver::resolve(symbolValue: '0.500', accountOverride: false, accountValue: '0.360');

    expect($resolved)->toBe('0.500');
});

it('forces account value when override is true even though symbol value is set', function (): void {
    $resolved = TpSlResolver::resolve(symbolValue: '0.500', accountOverride: true, accountValue: '0.360');

    expect($resolved)->toBe('0.360');
});

it('preserves decimal precision exactly (no float casting)', function (): void {
    // String I/O contract — we must NEVER cast through float since these
    // values feed downstream Math helpers that compare exact decimals.
    $resolved = TpSlResolver::resolve(symbolValue: '2.50', accountOverride: false, accountValue: '0.360');

    expect($resolved)->toBe('2.50')
        ->and($resolved)->toBeString();
});

it('handles SL-shaped values (decimal(5,2)) the same as TP-shaped values (decimal(6,3))', function (): void {
    // Same resolver method handles both — the rule is column-agnostic.
    $resolvedTp = TpSlResolver::resolve(symbolValue: '0.420', accountOverride: false, accountValue: '0.360');
    $resolvedSl = TpSlResolver::resolve(symbolValue: '3.00', accountOverride: false, accountValue: '2.50');

    expect($resolvedTp)->toBe('0.420')
        ->and($resolvedSl)->toBe('3.00');
});

it('treats empty-string symbol value as null fallback', function (): void {
    // Defensive — DB driver shouldn't return empty string on a nullable decimal,
    // but if it does we treat it as no-value (fallback).
    $resolved = TpSlResolver::resolve(symbolValue: '', accountOverride: false, accountValue: '0.360');

    expect($resolved)->toBe('0.360');
});

it('falls back when override is true and symbol value is the empty string', function (): void {
    $resolved = TpSlResolver::resolve(symbolValue: '', accountOverride: true, accountValue: '0.360');

    expect($resolved)->toBe('0.360');
});
