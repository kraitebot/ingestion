<?php

declare(strict_types=1);

use Kraite\Core\Support\Math;

it('returns false for null', function (): void {
    expect(Math::isPositive(null))->toBeFalse();
});

it('returns false for non-numeric types', function (mixed $value): void {
    expect(Math::isPositive($value))->toBeFalse();
})->with([
    'empty array' => [[]],
    'populated array' => [[1, 2, 3]],
    'stdClass' => [new stdClass],
    'boolean true' => [true],
    'boolean false' => [false],
]);

it('returns false for empty or sign-only strings', function (string $value): void {
    expect(Math::isPositive($value))->toBeFalse();
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'plus only' => ['+'],
    'minus only' => ['-'],
]);

it('returns false for malformed numeric strings', function (string $value): void {
    expect(Math::isPositive($value))->toBeFalse();
})->with([
    'letters' => ['foo'],
    'mixed' => ['12abc'],
    'double dot' => ['1.2.3'],
]);

it('returns false for zero in all supported types', function (mixed $value): void {
    expect(Math::isPositive($value))->toBeFalse();
})->with([
    'string zero' => ['0'],
    'padded zero' => ['0.0'],
    'eight-scale zero' => ['0.00000000'],
    'int zero' => [0],
    'float zero' => [0.0],
    'negative-zero string' => ['-0.000'],
]);

it('returns false for negative numbers', function (mixed $value): void {
    expect(Math::isPositive($value))->toBeFalse();
})->with([
    'negative int' => [-5],
    'negative float' => [-0.0001],
    'negative string' => ['-5'],
    'negative tiny string' => ['-0.00000001'],
]);

it('returns true for strictly positive numbers', function (mixed $value): void {
    expect(Math::isPositive($value))->toBeTrue();
})->with([
    'positive int' => [5],
    'positive float' => [1.5],
    'positive string' => ['5'],
    'eight-scale positive' => ['0.00000001'],
    'scientific notation' => ['1e-8'],
    'comma decimal' => ['1,5'],
    'leading plus' => ['+1.5'],
]);
