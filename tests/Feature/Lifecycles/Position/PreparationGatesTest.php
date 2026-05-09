<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\DetermineLeverageJob;
use Kraite\Core\Jobs\Atomic\Position\PreparePositionDataJob;
use Kraite\Core\Jobs\Atomic\Position\SetLeverageJob;
use Kraite\Core\Jobs\Atomic\Position\VerifyOrderNotionalForMarketOrderJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

/**
 * Pin the preparation-side guards on the position-dispatch chain.
 *
 * Order of execution: VerifyTradingPair → SetMarginMode → PreparePositionData
 *   → DetermineLeverage → SetLeverage → VerifyOrderNotional → PlaceMarketOrder.
 *
 * Each gate refuses to fire until its upstream peer has populated the
 * required attribute on the Position row. A regression that drops one
 * of these checks ships as a worker placing orders against a half-
 * prepared position — leverage 0, no margin, etc. The gate truth-table
 * below is the contract every dispatch step relies on.
 */

// ───────────────── PreparePositionData::startOrFail ─────────────────

it('PreparePositionData: passes when exchange_symbol_id is set', function (): void {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
    ]);

    expect((new PreparePositionDataJob($position->id))->startOrFail())->toBeTrue();
});

it('PreparePositionData: refuses when exchange_symbol_id is null (no symbol assigned)', function (): void {
    $position = Position::factory()->long()->create(['exchange_symbol_id' => null]);

    expect((new PreparePositionDataJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────── DetermineLeverage::startOrFail ─────────────────

it('DetermineLeverage: passes when margin is set (PrepareData has run)', function (): void {
    $position = Position::factory()->long()->create(['margin' => '50.00']);

    expect((new DetermineLeverageJob($position->id))->startOrFail())->toBeTrue();
});

it('DetermineLeverage: refuses when margin is null (PrepareData not yet complete)', function (): void {
    $position = Position::factory()->long()->create(['margin' => null]);

    expect((new DetermineLeverageJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────── SetLeverage::startOrFail ─────────────────

it('SetLeverage: passes when leverage is determined (DetermineLeverage ran)', function (): void {
    $position = Position::factory()->long()->create(['leverage' => 20]);

    expect((new SetLeverageJob($position->id))->startOrFail())->toBeTrue();
});

it('SetLeverage: refuses when leverage is null (DetermineLeverage not yet run)', function (): void {
    $position = Position::factory()->long()->create(['leverage' => null]);

    expect((new SetLeverageJob($position->id))->startOrFail())->toBeFalse();
});

// ───────────────── VerifyOrderNotional::startOrFail ─────────────────

it('VerifyOrderNotional: passes when margin + leverage + total_limit_orders are all set', function (): void {
    $position = Position::factory()->long()->create([
        'margin' => '50.00',
        'leverage' => 20,
        'total_limit_orders' => 4,
    ]);

    expect((new VerifyOrderNotionalForMarketOrderJob($position->id))->startOrFail())->toBeTrue();
});

it('VerifyOrderNotional: refuses when margin is null', function (): void {
    $position = Position::factory()->long()->create([
        'margin' => null,
        'leverage' => 20,
        'total_limit_orders' => 4,
    ]);

    expect((new VerifyOrderNotionalForMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('VerifyOrderNotional: refuses when leverage is null', function (): void {
    $position = Position::factory()->long()->create([
        'margin' => '50.00',
        'leverage' => null,
        'total_limit_orders' => 4,
    ]);

    expect((new VerifyOrderNotionalForMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('VerifyOrderNotional: refuses when total_limit_orders is null (PrepareData has not snapshotted it)', function (): void {
    $position = Position::factory()->long()->create([
        'margin' => '50.00',
        'leverage' => 20,
        'total_limit_orders' => null,
    ]);

    expect((new VerifyOrderNotionalForMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('VerifyOrderNotional: passes when total_limit_orders is 0 (simple-trade mode is a valid value, not unset)', function (): void {
    // Distinct from "null" — the Position has been through PrepareData
    // and its snapshot legitimately is zero (simple-trade mode).
    $position = Position::factory()->long()->create([
        'margin' => '50.00',
        'leverage' => 20,
        'total_limit_orders' => 0,
    ]);

    expect((new VerifyOrderNotionalForMarketOrderJob($position->id))->startOrFail())->toBeTrue();
});
