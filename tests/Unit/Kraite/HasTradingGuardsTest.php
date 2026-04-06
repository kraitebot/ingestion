<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Trading\Kraite;

test('canOpenPositions returns true when allow_opening_positions is true', function () {
    KraiteModel::query()->update(['allow_opening_positions' => true]);

    $account = Account::factory()->create();
    $engine = Kraite::withAccount($account);

    expect($engine->canOpenPositions())->toBeTrue();
});

test('canOpenPositions returns false when allow_opening_positions is false', function () {
    KraiteModel::query()->update(['allow_opening_positions' => false]);

    $account = Account::factory()->create();
    $engine = Kraite::withAccount($account);

    expect($engine->canOpenPositions())->toBeFalse();
});

test('canOpenPositions returns false when no engine record exists', function () {
    KraiteModel::query()->delete();

    $account = Account::factory()->create();
    $engine = Kraite::withAccount($account);

    expect($engine->canOpenPositions())->toBeFalse();
});

test('canOpenShorts returns true by default', function () {
    $account = Account::factory()->create();
    $engine = Kraite::withAccount($account);

    expect($engine->canOpenShorts())->toBeTrue();
});

test('canOpenLongs returns true by default', function () {
    $account = Account::factory()->create();
    $engine = Kraite::withAccount($account);

    expect($engine->canOpenLongs())->toBeTrue();
});

test('withAccount creates an instance with the given account', function () {
    $account = Account::factory()->create();

    $engine = Kraite::withAccount($account);

    expect($engine)->toBeInstanceOf(Kraite::class);
    expect($engine->account->id)->toBe($account->id);
});
