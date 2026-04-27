<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Observers\ExchangeSymbolObserver;

/**
 * Symmetric backtesting-review propagation across sibling exchange rows.
 *
 * Backtesting is a per-token decision, not per-exchange — the operator
 * approves (or rejects) a token once and the verdict applies on every
 * exchange that lists it. The observer fans BOTH:
 *
 *   - `was_backtesting_approved` (boolean tradability gate)
 *   - `backtesting_review_status` (admin-side review state, e.g. 'approved')
 *
 * Propagation is symmetric: any source row can fan out to all siblings
 * (linkage via `symbol_id` FK). `saveQuietly()` on the sibling save
 * prevents the observer from re-firing on every sibling — without that
 * guard, the first sibling save would cascade N more updates.
 */
function makeFourSiblingsForReviewTest(): array
{
    ExchangeSymbolObserver::resetBinanceSystemIdCache();

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $bitget = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    $symbol = Symbol::factory()->create();

    $binanceRow = ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $binance->id,
        'was_backtesting_approved' => false,
        'backtesting_review_status' => null,
    ]);

    $bitgetRow = ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $bitget->id,
        'was_backtesting_approved' => false,
        'backtesting_review_status' => null,
    ]);

    return [$binanceRow, $bitgetRow];
}

it('was_backtesting_approved flip on Binance propagates to siblings', function (): void {
    [$binance, $bitget] = makeFourSiblingsForReviewTest();

    $binance->was_backtesting_approved = true;
    $binance->save();

    $bitget->refresh();

    expect($bitget->was_backtesting_approved)->toBeTrue();
});

it('backtesting_review_status edit on Binance propagates to siblings', function (): void {
    [$binance, $bitget] = makeFourSiblingsForReviewTest();

    $binance->backtesting_review_status = 'approved';
    $binance->save();

    $bitget->refresh();

    expect($bitget->backtesting_review_status)->toBe('approved');
});

it('combined approval+review_status edit propagates both fields in one save', function (): void {
    [$binance, $bitget] = makeFourSiblingsForReviewTest();

    $binance->was_backtesting_approved = true;
    $binance->backtesting_review_status = 'approved';
    $binance->save();

    $bitget->refresh();

    expect($bitget->was_backtesting_approved)->toBeTrue()
        ->and($bitget->backtesting_review_status)->toBe('approved');
});

it('Bitget edit also propagates (symmetric — any source)', function (): void {
    [$binance, $bitget] = makeFourSiblingsForReviewTest();

    $bitget->was_backtesting_approved = true;
    $bitget->backtesting_review_status = 'approved';
    $bitget->save();

    $binance->refresh();

    expect($binance->was_backtesting_approved)->toBeTrue()
        ->and($binance->backtesting_review_status)->toBe('approved');
});

it('idempotent re-save with same review_status does not flap siblings', function (): void {
    [$binance, $bitget] = makeFourSiblingsForReviewTest();

    $binance->was_backtesting_approved = true;
    $binance->backtesting_review_status = 'approved';
    $binance->save();

    $bitget->refresh();
    $bitgetUpdatedAt = $bitget->updated_at;

    $binance->was_backtesting_approved = true;
    $binance->backtesting_review_status = 'approved';
    $binance->save();

    $bitget->refresh();

    expect((string) $bitget->updated_at)->toBe((string) $bitgetUpdatedAt);
});

it('rejection status propagates same as approval (any review_status value flows)', function (): void {
    [$binance, $bitget] = makeFourSiblingsForReviewTest();

    $binance->backtesting_review_status = 'rejected';
    $binance->save();

    $bitget->refresh();

    expect($bitget->backtesting_review_status)->toBe('rejected');
});
