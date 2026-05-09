<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;

/**
 * Pin the Account-level helpers the dispatcher reads on every cron tick.
 *
 *   - maxPositionSlots: LONG + SHORT cap. The slot guard rejects new
 *     position creations once `opened()` count hits this. A regression
 *     that returns longs only ships as accounts opening 2x their cap.
 *
 *   - isHedgeMode/isOneWayMode: drives the mapper branch for every
 *     order placement. Wrong mode = positionSide param wrong = vendor
 *     reject (-4061 "position side does not match user setting") on
 *     every entry.
 *
 *   - active() / tradeable() scopes: dispatcher's eligibility filter.
 *     A regression that admits inactive or can_trade=0 accounts ships
 *     as positions opening on accounts the operator has paused.
 */
it('maxPositionSlots returns the sum of LONG + SHORT caps', function (): void {
    $account = Account::factory()->create([
        'total_positions_long' => 3,
        'total_positions_short' => 4,
    ]);

    expect($account->maxPositionSlots())->toBe(7);
});

it('maxPositionSlots returns 0 when both caps are 0 (account paused)', function (): void {
    $account = Account::factory()->create([
        'total_positions_long' => 0,
        'total_positions_short' => 0,
    ]);

    expect($account->maxPositionSlots())->toBe(0);
});

it('isHedgeMode returns true when on_hedge_mode is set', function (): void {
    $account = Account::factory()->create(['on_hedge_mode' => true]);

    expect($account->isHedgeMode())->toBeTrue()
        ->and($account->isOneWayMode())->toBeFalse();
});

it('isHedgeMode returns false on a one-way account (Karine Binance)', function (): void {
    $account = Account::factory()->create(['on_hedge_mode' => false]);

    expect($account->isHedgeMode())->toBeFalse()
        ->and($account->isOneWayMode())->toBeTrue();
});

it('isOneWayMode is the strict inverse of isHedgeMode (no third state)', function (bool $hedge): void {
    $account = Account::factory()->create(['on_hedge_mode' => $hedge]);

    expect($account->isHedgeMode())->toBe($hedge)
        ->and($account->isOneWayMode())->toBe(! $hedge);
})->with([
    'hedge' => [true],
    'one-way' => [false],
]);

it('active() scope filters by is_active=true', function (): void {
    $on = Account::factory()->create(['is_active' => true]);
    Account::factory()->create(['is_active' => false]);

    $ids = Account::active()->pluck('id')->all();

    expect($ids)->toContain($on->id)
        ->and($ids)->toHaveCount(1);
});

it('tradeable() scope filters by can_trade=true (operator pause flag)', function (): void {
    $tradeable = Account::factory()->create(['can_trade' => true]);
    Account::factory()->create(['can_trade' => false]);

    $ids = Account::tradeable()->pluck('id')->all();

    expect($ids)->toContain($tradeable->id)
        ->and($ids)->toHaveCount(1);
});

it('active and tradeable scopes compose: only is_active=1 AND can_trade=1', function (): void {
    $active_tradeable = Account::factory()->create(['is_active' => true, 'can_trade' => true]);
    Account::factory()->create(['is_active' => true, 'can_trade' => false]);
    Account::factory()->create(['is_active' => false, 'can_trade' => true]);
    Account::factory()->create(['is_active' => false, 'can_trade' => false]);

    $rows = Account::active()->tradeable()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($active_tradeable->id);
});
