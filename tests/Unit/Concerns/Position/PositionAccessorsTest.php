<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

/**
 * Pin the Position accessor contract for the values views, notifications,
 * and downstream jobs read off the model. These accessors are quietly
 * load-bearing — a regression that returns null instead of "0" from
 * `pnl` cascades into a SQL "Type juggling" warning and, worse, a NULL
 * into the notification template that ships as an empty-PnL alert.
 */
it('parsed_trading_pair_extended concatenates pair + direction', function (): void {
    $position = Position::factory()->long()->create([
        'parsed_trading_pair' => 'APEUSDT',
    ]);

    expect($position->parsed_trading_pair_extended)->toBe('APEUSDT/LONG');
});

it('parsed_trading_pair_extended handles SHORT direction', function (): void {
    $position = Position::factory()->short()->create([
        'parsed_trading_pair' => 'TONUSDT',
    ]);

    expect($position->parsed_trading_pair_extended)->toBe('TONUSDT/SHORT');
});

it('parsed_trading_pair_extended falls back to "/DIRECTION" when pair is null', function (): void {
    $position = Position::factory()->long()->create([
        'parsed_trading_pair' => null,
    ]);

    expect($position->parsed_trading_pair_extended)->toBe('/LONG');
});

it('current_price falls back to "0" when no exchange symbol is set', function (): void {
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => null,
    ]);

    expect($position->current_price)->toBe('0');
});

it('current_price falls back to "0" when exchange symbol has no recent candles (current_price accessor returns null)', function (): void {
    // current_price on ExchangeSymbol is itself an accessor that derives
    // from the latest 5m candle. With no candles ingested for this symbol
    // (factory does not seed candles), the accessor returns null and
    // Position::current_price coerces it to "0" — the contract that keeps
    // notification templates from emitting NULL.
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
    ]);

    expect($position->current_price)->toBe('0');
});

it('unrealized_pnl accessor coerces null to "0" (template-safe — no NULL into notifications)', function (): void {
    // A position with no exchange symbol can't compute unrealized PnL.
    // Accessor MUST ship "0" not null so notification templates render cleanly.
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => null,
    ]);

    expect($position->unrealized_pnl)->toBeString()
        ->and($position->unrealized_pnl)->toBe('0');
});

it('daily_variation_percentage falls back to "0.00" with no exchange symbol', function (): void {
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => null,
    ]);

    expect($position->daily_variation_percentage)->toBe('0.00');
});

it('daily_variation_percentage falls back to "0.00" when exchange symbol has no recent candle (current_price=null)', function (): void {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
    ]);

    expect($position->daily_variation_percentage)->toBe('0.00');
});

it('alpha_limit_percentage formats to one decimal place (presentation contract)', function (): void {
    $position = Position::factory()->long()->create();

    $value = $position->alpha_limit_percentage;

    expect($value)->toBeString()
        ->and($value)->toMatch('/^\d+\.\d$/');
});
