<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;

it('automatically blocks non-allow-listed tokens without changing the sysadmin flag', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $allowed = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'ETH',
        'is_manually_enabled' => true,
    ]);
    $blocked = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'NOTALLOWLISTED',
        'is_manually_enabled' => true,
    ]);

    $this->artisan('kraite:disable-volatile-tokens')->assertSuccessful();

    expect($allowed->fresh()->system_disabled_at)->toBeNull()
        ->and($blocked->fresh()->is_manually_enabled)->toBeTrue()
        ->and($blocked->fresh()->system_disabled_at->isSameSecond(now()))->toBeTrue()
        ->and($blocked->fresh()->system_disabled_reason)->toBe('token_not_allow_listed');

    $updatedAt = $blocked->fresh()->updated_at;

    $this->artisan('kraite:disable-volatile-tokens')->assertSuccessful();

    expect($blocked->fresh()->updated_at->eq($updatedAt))->toBeTrue();
});

it('dry run leaves automatic and manual state untouched', function (): void {
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'NOTALLOWLISTED',
        'is_manually_enabled' => true,
    ]);

    $this->artisan('kraite:disable-volatile-tokens --dry-run')->assertSuccessful();

    expect($exchangeSymbol->fresh()->is_manually_enabled)->toBeTrue()
        ->and($exchangeSymbol->fresh()->system_disabled_at)->toBeNull()
        ->and($exchangeSymbol->fresh()->system_disabled_reason)->toBeNull();
});
