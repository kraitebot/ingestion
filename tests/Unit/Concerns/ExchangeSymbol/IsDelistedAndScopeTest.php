<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;

/**
 * Pin the delisting contract.
 *
 *   - `is_marked_for_delisting` blocks new openings as soon as an exchange
 *     announces or hints at removal. It does not claim the contract has
 *     already disappeared.
 *
 *   - isDelisted() / delistedAt() are terminal facts backed by a past
 *     `delivery_at`; they stay false/null for warning-only rows.
 *
 *   - notDelisted() scope is the SQL form of isDelisted() — must
 *     match instance-level truth so SQL-driven dispatch matches the
 *     in-memory view.
 */
function buildDelistableSymbol(array $overrides = []): ExchangeSymbol
{
    $token = 'DEL'.mb_strtoupper(Str::random(6));

    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true, 'recvwindow_margin' => 1000]
    );

    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create(array_merge([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'is_marked_for_delisting' => false,
        'delivery_at' => null,
    ], $overrides));
}

it('does not call a warning-only symbol fully delisted', function (): void {
    $symbol = buildDelistableSymbol(['is_marked_for_delisting' => true]);

    expect($symbol->isDelisted())->toBeFalse()
        ->and($symbol->delistedAt())->toBeNull();
});

it('isDelisted returns true when delivery_at is in the past (futures settled)', function (): void {
    $symbol = buildDelistableSymbol(['delivery_at' => now()->subDay()]);

    expect($symbol->isDelisted())->toBeTrue();
});

it('isDelisted returns false when delivery_at is in the future (still trading)', function (): void {
    $symbol = buildDelistableSymbol(['delivery_at' => now()->addDay()]);

    expect($symbol->isDelisted())->toBeFalse();
});

it('isDelisted returns false on a clean live symbol', function (): void {
    $symbol = buildDelistableSymbol();

    expect($symbol->isDelisted())->toBeFalse();
});

it('delistedAt returns null on a live symbol', function (): void {
    $symbol = buildDelistableSymbol();

    expect($symbol->delistedAt())->toBeNull();
});

it('delistedAt prefers delivery_at over updated_at when delivery is past', function (): void {
    $deliveredAt = now()->subDays(7);
    $symbol = buildDelistableSymbol([
        'delivery_at' => $deliveredAt,
        'is_marked_for_delisting' => true,
    ]);

    expect($symbol->delistedAt())->not->toBeNull()
        ->and($symbol->delistedAt()->toDateString())->toBe($deliveredAt->toDateString());
});

it('notDelisted scope matches the instance-level isDelisted truth', function (): void {
    $live = buildDelistableSymbol();
    $warningOnly = buildDelistableSymbol(['is_marked_for_delisting' => true]);
    $expiredFuture = buildDelistableSymbol(['delivery_at' => now()->subHour()]);
    $futureDelivery = buildDelistableSymbol(['delivery_at' => now()->addHour()]);

    $ids = ExchangeSymbol::notDelisted()->pluck('id')->all();

    expect($ids)->toContain($live->id)
        ->and($ids)->toContain($warningOnly->id)
        ->and($ids)->toContain($futureDelivery->id)
        ->and($ids)->not->toContain($expiredFuture->id);
});
