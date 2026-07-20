<?php

declare(strict_types=1);

use Kraite\Core\Support\Health\ForeignActivityDetector;

/*
 * ForeignActivityDetector is the pure classifier answering: "does this
 * exchange account carry positions/orders the USER placed themselves?"
 *
 * Foreign = present on the exchange but neither a live Kraite record
 * nor a Kraite leftover from a position closed within the match window.
 * Its verdict drives the automatic sync of the account's
 * allow_other_positions / allow_other_orders protection flags.
 */

test('classifies nothing foreign when exchange mirrors Kraite exactly', function (): void {
    $report = ForeignActivityDetector::detect(
        exchangeOpenOrderIds: ['101', '102'],
        exchangePositionKeys: ['BTCUSDT:LONG'],
        kraiteOpenOrderIds: ['101', '102'],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: [],
        kraiteRecentlyClosedPositionKeys: [],
    );

    expect($report->foreignOrderIds)->toBe([])
        ->and($report->foreignPositionKeys)->toBe([])
        ->and($report->hasAny())->toBeFalse();
});

test('classifies unknown exchange positions and orders as foreign', function (): void {
    $report = ForeignActivityDetector::detect(
        exchangeOpenOrderIds: ['101', 'user-9'],
        exchangePositionKeys: ['BTCUSDT:LONG', 'DOGEUSDT:SHORT'],
        kraiteOpenOrderIds: ['101'],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: [],
        kraiteRecentlyClosedPositionKeys: [],
    );

    expect($report->foreignOrderIds)->toBe(['user-9'])
        ->and($report->foreignPositionKeys)->toBe(['DOGEUSDT:SHORT'])
        ->and($report->hasAny())->toBeTrue();
});

test('does not classify Kraite leftovers within the match window as foreign', function (): void {
    $report = ForeignActivityDetector::detect(
        exchangeOpenOrderIds: ['leftover-order'],
        exchangePositionKeys: ['ETHUSDT:SHORT'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: ['leftover-order'],
        kraiteRecentlyClosedPositionKeys: ['ETHUSDT:SHORT'],
    );

    expect($report->foreignOrderIds)->toBe([])
        ->and($report->foreignPositionKeys)->toBe([])
        ->and($report->hasAny())->toBeFalse();
});

test('a single foreign order with no foreign position still reports foreign activity', function (): void {
    $report = ForeignActivityDetector::detect(
        exchangeOpenOrderIds: ['user-limit-1'],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        kraiteRecentlyClosedPositionKeys: [],
    );

    expect($report->foreignOrderIds)->toBe(['user-limit-1'])
        ->and($report->hasAny())->toBeTrue();
});

test('empty exchange reports nothing foreign', function (): void {
    $report = ForeignActivityDetector::detect(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: ['1'],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: ['2'],
        kraiteRecentlyClosedPositionKeys: ['XRPUSDT:LONG'],
    );

    expect($report->hasAny())->toBeFalse();
});
