<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;

it('selects and excludes API systems through the canonical vocabulary', function (): void {
    $binance = ApiSystem::factory()->exchange()->create(['canonical' => 'scope-binance']);
    $bybit = ApiSystem::factory()->exchange()->create(['canonical' => 'scope-bybit']);
    $taapi = ApiSystem::factory()->taapi()->create(['canonical' => 'scope-taapi']);

    $selectedId = ApiSystem::query()->canonical('scope-binance')->value('id');
    $otherIds = ApiSystem::query()
        ->excludingCanonical('scope-binance')
        ->whereKey([$binance->id, $bybit->id, $taapi->id])
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($selectedId)->toBe($binance->id)
        ->and($otherIds)->toBe([$bybit->id, $taapi->id]);
});
