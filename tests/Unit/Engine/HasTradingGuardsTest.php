<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Engine as EngineModel;
use Kraite\Core\Trading\Engine;

test('canOpenPositions returns true when allow_opening_positions is true', function () {
    EngineModel::query()->update(['allow_opening_positions' => true]);

    $account = Account::factory()->create();
    $engine = Engine::withAccount($account);

    expect($engine->canOpenPositions())->toBeTrue();
});

test('canOpenPositions returns false when allow_opening_positions is false', function () {
    EngineModel::query()->update(['allow_opening_positions' => false]);

    $account = Account::factory()->create();
    $engine = Engine::withAccount($account);

    expect($engine->canOpenPositions())->toBeFalse();
});

test('canOpenPositions returns false when no engine record exists', function () {
    EngineModel::query()->delete();

    $account = Account::factory()->create();
    $engine = Engine::withAccount($account);

    expect($engine->canOpenPositions())->toBeFalse();
});

test('canOpenShorts returns true by default', function () {
    $account = Account::factory()->create();
    $engine = Engine::withAccount($account);

    expect($engine->canOpenShorts())->toBeTrue();
});

test('canOpenLongs returns true by default', function () {
    $account = Account::factory()->create();
    $engine = Engine::withAccount($account);

    expect($engine->canOpenLongs())->toBeTrue();
});

test('withAccount creates an instance with the given account', function () {
    $account = Account::factory()->create();

    $engine = Engine::withAccount($account);

    expect($engine)->toBeInstanceOf(Engine::class);
    expect($engine->account->id)->toBe($account->id);
});
