<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\ExchangeSymbol\CopyDirectionToOtherExchangesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * Cross-exchange direction bridging keys on the canonical CMC `symbol_id`
 * (naming-agnostic identity), not the ticker string. This is the root-cause
 * fix for the 2026-06 orphans: BitGet FLOKI / SHIB and Bybit SKYAI1 are the
 * SAME assets as Binance 1000FLOKI / 1000SHIB / SKYAI (shared symbol_id), but
 * no hand-seeded TokenMapper row existed for those exchanges, so they sat with
 * overlaps_with_binance=0, null indicators, and perpetually tripped the
 * indicator-stale watchdog.
 */
uses()->group('feature', 'exchange-symbols', 'symbol-id-bridge');

it('bridges a 1000x-named symbol to its plain-named sibling via symbol_id, no TokenMapper', function (): void {
    $binance = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitget = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'BitGet']);

    // Binance lists the 1000x contract with a concluded direction.
    $source = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => '1000FLOKI',
        'quote' => 'USDT',
        'symbol_id' => 72,
        'direction' => 'SHORT',
        'indicators_timeframe' => '1h',
        'indicators_synced_at' => now(),
    ]);

    // BitGet lists the plain token — same asset (symbol_id 72), different name,
    // NO TokenMapper row. This is the orphan shape.
    $target = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitget->id,
        'token' => 'FLOKI',
        'quote' => 'USDT',
        'symbol_id' => 72,
        'direction' => null,
        'indicators_synced_at' => null,
    ]);

    // 1) Observer flags overlap purely from the shared symbol_id — no token
    //    match, no mapper.
    expect($target->fresh()->overlaps_with_binance)->toBeTrue();

    // 2) Copy job resolves the target by symbol_id and copies the direction.
    (new CopyDirectionToOtherExchangesJob($source->id))->compute();

    $target->refresh();

    expect($target->direction)->toBe('SHORT')
        ->and($target->indicators_synced_at)->not->toBeNull()
        ->and($target->indicators_timeframe)->toBe('1h')
        ->and($target->has_no_indicator_data)->toBeFalse();
});

it('does NOT bridge when symbol_id differs — distinct assets / ticker collision', function (): void {
    // Same-ish ticker, DIFFERENT canonical asset. A naming coincidence must
    // never link two assets — the safety guard against ticker collisions.
    $binance = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitget = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'BitGet']);

    $source = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => '1000FLOKI',
        'quote' => 'USDT',
        'symbol_id' => 72,
        'direction' => 'SHORT',
        'indicators_synced_at' => now(),
    ]);

    $other = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitget->id,
        'token' => 'FLOKI',
        'quote' => 'USDT',
        'symbol_id' => 999, // different asset
        'direction' => null,
        'indicators_synced_at' => null,
    ]);

    expect($other->fresh()->overlaps_with_binance)->toBeFalse();

    (new CopyDirectionToOtherExchangesJob($source->id))->compute();

    expect($other->fresh()->direction)->toBeNull();
});

it('still bridges a same-named symbol with a null symbol_id via the token fallback', function (): void {
    // The change is additive — the exact-token path must still work so the
    // common direct-match case (and CMC-unresolved rows) never regress.
    $binance = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitget = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'BitGet']);

    $source = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'ENA',
        'quote' => 'USDT',
        'symbol_id' => null,
        'direction' => 'LONG',
        'indicators_synced_at' => now(),
    ]);

    $target = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitget->id,
        'token' => 'ENA',
        'quote' => 'USDT',
        'symbol_id' => null,
        'direction' => null,
        'indicators_synced_at' => null,
    ]);

    expect($target->fresh()->overlaps_with_binance)->toBeTrue();

    (new CopyDirectionToOtherExchangesJob($source->id))->compute();

    expect($target->fresh()->direction)->toBe('LONG');
});
