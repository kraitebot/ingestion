<?php

declare(strict_types=1);

use Kraite\Core\Support\Backtest\OneTime\AutomaticBacktestPolicy;

function assessAutomaticBacktest(array $overrides = []): array
{
    $inputs = array_replace_recursive([
        'isPending' => true,
        'isBinance' => true,
        'isLinked' => true,
        'configurationMatches' => true,
        'coverageGate' => ['ready' => true],
        'totals' => [
            'candles' => 180,
            'stops' => 4,
            'tp_market_only' => 100,
            'reboundable' => 100,
            'inconclusive' => 156,
            'skipped' => 0,
        ],
    ], $overrides);

    return (new AutomaticBacktestPolicy)->assess(...$inputs);
}

it('approves exactly four stops', function (): void {
    $assessment = assessAutomaticBacktest();

    expect($assessment['eligible'])->toBeTrue()
        ->and($assessment['resolved_simulations'])->toBe(204)
        ->and($assessment['reason_codes'])->toBe([]);
});

it('keeps exactly five stops pending', function (): void {
    $assessment = assessAutomaticBacktest([
        'totals' => ['stops' => 5],
    ]);

    expect($assessment['eligible'])->toBeFalse()
        ->and($assessment['reason_codes'])->toContain('stop_threshold_reached');
});

it('requires every automatic approval safety gate', function (array $overrides, string $reason): void {
    $assessment = assessAutomaticBacktest($overrides);

    expect($assessment['eligible'])->toBeFalse()
        ->and($assessment['reason_codes'])->toContain($reason);
})->with([
    'pending decision' => [['isPending' => false], 'already_reviewed'],
    'Binance source' => [['isBinance' => false], 'not_binance'],
    'linked token' => [['isLinked' => false], 'unlinked_token'],
    'live ladder envelope' => [['configurationMatches' => false], 'configuration_mismatch'],
    'fresh contiguous coverage' => [['coverageGate' => ['ready' => false]], 'coverage_not_ready'],
    '180 start candles' => [['totals' => ['candles' => 179]], 'insufficient_sample'],
    'resolved simulations' => [[
        'totals' => [
            'stops' => 0,
            'tp_market_only' => 0,
            'reboundable' => 0,
        ],
    ], 'no_resolved_simulations'],
    'zero skipped simulations' => [['totals' => ['skipped' => 1]], 'skipped_simulations'],
]);
