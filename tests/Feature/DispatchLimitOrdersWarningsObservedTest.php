<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\DispatchLimitOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\AppLog;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Surfaces the structured warnings that Kraite::calculateLimitOrdersData()
 * already collects (price_clamped + rung_dropped_zero_qty) so operators see
 * symbol-bounds compression on the success path — not just on the throwing
 * min_notional rejection path that the pre-gate already logs.
 *
 * The math layer has emitted warnings since v1.0 but production callers
 * passed withMeta=false and discarded them. This test pins the new
 * contract: DispatchLimitOrdersJob MUST persist non-empty warnings to
 * the position's appLog as `ladder_warnings_observed` BEFORE any
 * Step::create runs, so a downstream Step setup failure cannot eat the
 * symbol-health signal.
 */
beforeEach(function (): void {
    AppLog::enable();
});

function buildClampingPositionForLadderWarningsTest(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'CLMPTEST']);

    // Stage 1.5% gap on a LONG ladder anchored at 0.16490 with min_price set
    // just under the entry but ABOVE every rung the gap math would produce
    // (0.16242, 0.15994, 0.15745, 0.15497). All four rungs clamp to 0.16400
    // → we expect four `price_clamped` warnings on the success path.
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'CLMPTEST',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'percentage_gap_long' => '1.5',
        'min_price' => '0.16400',
        'max_price' => '1.0',
        'min_notional' => '5',
        'tick_size' => '0.00001',
        'price_precision' => 5,
        'quantity_precision' => 0,
        'limit_quantity_multipliers' => [2, 2, 2, 2],
        'total_limit_orders' => 4,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'quantity' => '5872',
        'opening_price' => '0.16490',
        'total_limit_orders' => 4,
    ]);
}

it('logs ladder_warnings_observed appLog when symbol bounds clamp at least one rung', function (): void {
    $position = buildClampingPositionForLadderWarningsTest();

    try {
        (new DispatchLimitOrdersJob($position->id))->compute();
    } catch (Throwable) {
        // Step typed-prop is uninitialised when a job is invoked
        // outside the dispatcher loop — the appLog must already have
        // landed before Step::create is reached, so the throw here is
        // expected and irrelevant to this assertion.
    }

    $log = AppLog::query()
        ->where('loggable_type', Position::class)
        ->where('loggable_id', $position->id)
        ->where('event', 'ladder_warnings_observed')
        ->latest()
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['warnings'])->toBeArray()->not->toBeEmpty()
        ->and($log->metadata['warnings'][0]['type'])->toBe('price_clamped');
});

it('does NOT log ladder_warnings_observed in simple-trade mode (no rungs to warn about)', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'SIMPLETEST']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'SIMPLETEST',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'limit_quantity_multipliers' => [2, 2, 2, 2],
        'total_limit_orders' => 0,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'quantity' => '5872',
        'opening_price' => '0.16490',
        'total_limit_orders' => 0,
    ]);

    try {
        (new DispatchLimitOrdersJob($position->id))->compute();
    } catch (Throwable) {
        // Same uninitialised-step caveat — irrelevant here because
        // simple-trade mode skips the Step::create block entirely.
    }

    $log = AppLog::query()
        ->where('loggable_type', Position::class)
        ->where('loggable_id', $position->id)
        ->where('event', 'ladder_warnings_observed')
        ->first();

    expect($log)->toBeNull();
});
