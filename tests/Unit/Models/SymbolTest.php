<?php

declare(strict_types=1);

use Kraite\Core\Models\Symbol;

it('creates a symbol with cmc_ranking', function () {
    $symbol = Symbol::factory()->create([
        'token' => 'TESTBTC'.uniqid(),
        'cmc_id' => 1,
        'cmc_ranking' => 1,
    ]);

    expect($symbol->cmc_ranking)->toBe(1);
    expect($symbol->cmc_id)->toBe(1);
});

it('creates a symbol with null cmc_ranking', function () {
    $symbol = Symbol::factory()->create([
        'token' => 'NEWTOKEN'.uniqid(),
        'cmc_ranking' => null,
    ]);

    expect($symbol->cmc_ranking)->toBeNull();
});

it('creates a symbol with is_stable_coin flag', function () {
    $symbol = Symbol::factory()->create([
        'token' => 'TESTUSDT'.uniqid(),
        'is_stable_coin' => true,
    ]);

    expect($symbol->is_stable_coin)->toBeTrue();
});

it('defaults is_stable_coin to false', function () {
    $symbol = Symbol::factory()->create([
        'is_stable_coin' => false,
    ]);

    expect($symbol->is_stable_coin)->toBeFalse();
});

it('can use stablecoin factory state', function () {
    $symbol = Symbol::factory()->stablecoin()->create();

    expect($symbol->is_stable_coin)->toBeTrue();
});

it('casts is_stable_coin to boolean', function () {
    $symbol = Symbol::factory()->create([
        'is_stable_coin' => 1,
    ]);

    expect($symbol->is_stable_coin)->toBeBool();
    expect($symbol->is_stable_coin)->toBeTrue();
});

it('stores cmc_ranking correctly from factory', function () {
    $symbol = Symbol::factory()->create([
        'token' => 'RANKEDTOKEN'.uniqid(),
        'cmc_ranking' => 42,
    ]);

    expect($symbol->cmc_ranking)->toBe(42);
});

it('stores stablecoin data correctly', function () {
    $symbol = Symbol::factory()->stablecoin()->create([
        'token' => 'STABLETEST'.uniqid(),
        'cmc_ranking' => 5,
    ]);

    expect($symbol->is_stable_coin)->toBeTrue();
    expect($symbol->cmc_ranking)->toBe(5);
});
