<?php

declare(strict_types=1);

use Kraite\Core\Enums\BacktestTimeframe;

it('owns the complete supported backtest timeframe vocabulary', function (): void {
    expect(BacktestTimeframe::values())->toBe(['1h', '4h', '12h', '1d']);
});

it('owns the interval duration for each supported timeframe', function (string $timeframe, int $seconds): void {
    expect(BacktestTimeframe::from($timeframe)->seconds())->toBe($seconds);
})->with([
    'one hour' => ['1h', 3_600],
    'four hours' => ['4h', 14_400],
    'twelve hours' => ['12h', 43_200],
    'one day' => ['1d', 86_400],
]);
