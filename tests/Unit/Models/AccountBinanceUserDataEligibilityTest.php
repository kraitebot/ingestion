<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;

/**
 * Pin the Binance user-data stream eligibility predicate.
 *
 * The same conditions ("is this account streamable on Binance right now?")
 * are checked in two places — the daemon's account-discovery query
 * (`Account::scopeEligibleForBinanceUserDataStream`) and the keepalive
 * cron's per-row check (`Account::isEligibleForBinanceUserDataStream`).
 * Both must resolve identically; centralising on the Account model
 * means a future condition (subscription tier, stream-disabled flag,
 * etc.) is a single edit.
 */
uses(RefreshDatabase::class);

it('isEligibleForBinanceUserDataStream returns true on the canonical happy path', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => 'real-key',
        'binance_api_secret' => 'real-secret',
    ]);

    expect($account->isEligibleForBinanceUserDataStream())->toBeTrue();
});

it('isEligibleForBinanceUserDataStream returns false when account is inactive', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => false,
        'binance_api_key' => 'real-key',
        'binance_api_secret' => 'real-secret',
    ]);

    expect($account->isEligibleForBinanceUserDataStream())->toBeFalse();
});

it('isEligibleForBinanceUserDataStream returns false when binance_api_key is null', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => null,
        'binance_api_secret' => 'real-secret',
    ]);

    expect($account->isEligibleForBinanceUserDataStream())->toBeFalse();
});

it('isEligibleForBinanceUserDataStream returns false when binance_api_secret is null (mismatched credential pair)', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => 'real-key',
        'binance_api_secret' => null,
    ]);

    expect($account->isEligibleForBinanceUserDataStream())->toBeFalse();
});

it('isEligibleForBinanceUserDataStream returns false when account is on a different exchange', function (): void {
    ApiSystem::factory()->create(['canonical' => 'binance']);
    $bybit = ApiSystem::factory()->create(['canonical' => 'bybit']);

    $account = Account::factory()->create([
        'api_system_id' => $bybit->id,
        'is_active' => true,
        'binance_api_key' => 'real-key',  // historical key still present
        'binance_api_secret' => 'real-secret',
    ]);

    expect($account->isEligibleForBinanceUserDataStream())->toBeFalse();
});

it('scopeEligibleForBinanceUserDataStream filters to the same predicate as the instance check', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $bybit = ApiSystem::factory()->create(['canonical' => 'bybit']);

    $eligible = Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => 'k',
        'binance_api_secret' => 's',
    ]);
    Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => false,
        'binance_api_key' => 'k',
        'binance_api_secret' => 's',
    ]);
    Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => null,
        'binance_api_secret' => 's',
    ]);
    Account::factory()->create([
        'api_system_id' => $binance->id,
        'is_active' => true,
        'binance_api_key' => 'k',
        'binance_api_secret' => null, // mismatched pair — must NOT match
    ]);
    Account::factory()->create([
        'api_system_id' => $bybit->id,
        'is_active' => true,
        'binance_api_key' => 'k',
        'binance_api_secret' => 's',
    ]);

    $ids = Account::query()->eligibleForBinanceUserDataStream()->pluck('id')->all();

    expect($ids)->toBe([$eligible->id]);
});

it('scopeEligibleForBinanceUserDataStream returns no rows when no Binance api_system exists', function (): void {
    // Edge case: fresh install / mid-migration where the binance row
    // hasn't been seeded yet. Scope must not match anything.
    Account::factory()->create([
        'is_active' => true,
        'binance_api_key' => 'k',
        'binance_api_secret' => 's',
    ]);

    expect(Account::query()->eligibleForBinanceUserDataStream()->count())->toBe(0);
});
