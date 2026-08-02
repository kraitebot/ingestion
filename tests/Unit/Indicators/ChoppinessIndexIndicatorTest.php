<?php

declare(strict_types=1);

use Kraite\Core\Indicators\RefreshData\ChoppinessIndexIndicator;

function choppinessIndicatorWithData(array $data): ChoppinessIndexIndicator
{
    $indicator = (new ReflectionClass(ChoppinessIndexIndicator::class))->newInstanceWithoutConstructor();
    $indicator->load($data);

    return $indicator;
}

test('rejects the production TAAPI array payload when CHOP exceeds the threshold', function (): void {
    $indicator = choppinessIndicatorWithData([
        'value' => ['56.35633998069864958325'],
    ]);

    expect($indicator->conclusion())->toBeFalse();
});

test('applies the CHOP threshold to the latest value for scalar and array payloads', function (array $data, bool $expected): void {
    expect(choppinessIndicatorWithData($data)->conclusion())->toBe($expected);
})->with([
    'scalar below threshold' => [['value' => '54.999'], true],
    'scalar at threshold' => [['value' => '55.000'], false],
    'single array below threshold' => [['value' => ['54.999']], true],
    'single array at threshold' => [['value' => ['55.000']], false],
    'latest array value below threshold' => [['value' => ['58.000', '54.000']], true],
    'latest array value above threshold' => [['value' => ['54.000', '58.000']], false],
]);

test('keeps the existing permissive behavior for missing or malformed CHOP payloads', function (array $data): void {
    expect(choppinessIndicatorWithData($data)->conclusion())->toBeTrue();
})->with([
    'missing value' => [[]],
    'empty value array' => [['value' => []]],
    'non-numeric scalar' => [['value' => 'unavailable']],
    'non-numeric latest array value' => [['value' => ['54.000', 'unavailable']]],
]);
