<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;

/**
 * `AssignBestTokensToPositionSlotsJob::countPositionsByDirection` —
 * one-way mode response shape support.
 *
 * Hedge mode response: one row per `(symbol, positionSide=LONG|SHORT)`
 * with always-positive `positionAmt`.
 *
 * One-way mode response: one row per symbol with `positionSide=BOTH`
 * and SIGNED `positionAmt` (positive = LONG, negative = SHORT, zero =
 * empty).
 *
 * Today's filter only matches `positionSide === $direction` literally,
 * so a one-way `BOTH` row is invisible to both LONG and SHORT counts.
 * On a one-way account that has open positions on the exchange, the
 * count would falsely return zero — slot calc thinks the account is
 * empty and tries to open new ones over the cap.
 *
 * Fix: when `positionSide === 'BOTH'`, use the sign of `positionAmt`.
 * The mapper layer already preserves `BOTH` from the live response;
 * this is the consumer's job to interpret.
 */
function makeJobForOneWayCount(): AssignBestTokensToPositionSlotsJob
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $account = Account::factory()->oneWayMode()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return new AssignBestTokensToPositionSlotsJob($account->id);
}

it('hedge response shape: counts LONG row as LONG only', function (): void {
    $job = makeJobForOneWayCount();

    $snapshot = [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.5'],
    ];

    expect($job->countPositionsByDirection($snapshot, 'LONG'))->toBe(1);
    expect($job->countPositionsByDirection($snapshot, 'SHORT'))->toBe(0);
});

it('hedge response shape: counts SHORT row as SHORT only', function (): void {
    $job = makeJobForOneWayCount();

    $snapshot = [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '0.5'],
    ];

    expect($job->countPositionsByDirection($snapshot, 'LONG'))->toBe(0);
    expect($job->countPositionsByDirection($snapshot, 'SHORT'))->toBe(1);
});

it('one-way response shape: positionSide=BOTH with positive positionAmt counts as LONG', function (): void {
    $job = makeJobForOneWayCount();

    $snapshot = [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '0.5'],
    ];

    expect($job->countPositionsByDirection($snapshot, 'LONG'))->toBe(
        1,
        'One-way snapshot returns positionSide=BOTH; positive positionAmt means LONG.'
    );
    expect($job->countPositionsByDirection($snapshot, 'SHORT'))->toBe(0);
});

it('one-way response shape: positionSide=BOTH with negative positionAmt counts as SHORT', function (): void {
    $job = makeJobForOneWayCount();

    $snapshot = [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '-0.5'],
    ];

    expect($job->countPositionsByDirection($snapshot, 'SHORT'))->toBe(
        1,
        'One-way snapshot with negative positionAmt is a SHORT.'
    );
    expect($job->countPositionsByDirection($snapshot, 'LONG'))->toBe(0);
});

it('one-way response shape: positionSide=BOTH with zero positionAmt counts as nothing', function (): void {
    $job = makeJobForOneWayCount();

    $snapshot = [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '0'],
    ];

    expect($job->countPositionsByDirection($snapshot, 'LONG'))->toBe(0);
    expect($job->countPositionsByDirection($snapshot, 'SHORT'))->toBe(0);
});

it('mixed one-way snapshot counts LONG and SHORT independently by sign', function (): void {
    $job = makeJobForOneWayCount();

    $snapshot = [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '0.5'],
        ['symbol' => 'ETHUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '-1.0'],
        ['symbol' => 'SOLUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '2.5'],
        ['symbol' => 'AVAXUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '0'],
    ];

    expect($job->countPositionsByDirection($snapshot, 'LONG'))->toBe(2);
    expect($job->countPositionsByDirection($snapshot, 'SHORT'))->toBe(1);
});
