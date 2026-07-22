<?php

declare(strict_types=1);

use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Database\Seeders\KraiteSeeder;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Tests\Support\StepTester;
use Tests\Support\TestApiableJob;

test('deployment policy leaves Binance as the only active exchange while support APIs stay active', function (): void {
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance activation policy',
        'is_active' => false,
    ]);
    $bybit = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit activation policy',
        'is_active' => true,
    ]);
    $kucoin = ApiSystem::factory()->exchange()->create([
        'canonical' => 'kucoin',
        'name' => 'KuCoin activation policy',
        'is_active' => true,
    ]);
    $bitget = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget activation policy',
        'is_active' => true,
    ]);
    $futureExchange = ApiSystem::factory()->exchange()->create([
        'canonical' => 'future-exchange-activation-policy',
        'name' => 'Future exchange activation policy',
        'is_active' => true,
    ]);
    $taapi = ApiSystem::factory()->create([
        'canonical' => 'taapi-activation-policy',
        'name' => 'TAAPI activation policy',
        'is_active' => false,
    ]);

    expect($binance->is_active)->toBeFalse()
        ->and($bybit->is_active)->toBeTrue()
        ->and($kucoin->is_active)->toBeTrue()
        ->and($bitget->is_active)->toBeTrue()
        ->and($futureExchange->is_active)->toBeTrue()
        ->and($taapi->is_active)->toBeFalse();

    $migration = require dirname((new ReflectionClass(KraiteSeeder::class))->getFileName()).'/../migrations/2026_07_22_091121_set_binance_as_only_active_exchange.php';
    $migration->up();

    expect($binance->refresh()->is_active)->toBeTrue()
        ->and($bybit->refresh()->is_active)->toBeFalse()
        ->and($kucoin->refresh()->is_active)->toBeFalse()
        ->and($bitget->refresh()->is_active)->toBeFalse()
        ->and($futureExchange->refresh()->is_active)->toBeFalse()
        ->and($taapi->refresh()->is_active)->toBeTrue()
        ->and(ApiSystem::activeExchange()
            ->whereKey([$binance->id, $bybit->id, $kucoin->id, $bitget->id, $futureExchange->id])
            ->pluck('canonical')
            ->all())->toBe(['binance']);
});

test('fresh seed data keeps only Binance active among supported exchanges', function (): void {
    $systems = (new KraiteSeeder)->seedApiSystems();

    expect($systems['binance']->is_active)->toBeTrue()
        ->and($systems['bybit']->is_active)->toBeFalse()
        ->and($systems['kucoin']->is_active)->toBeFalse()
        ->and($systems['bitget']->is_active)->toBeFalse()
        ->and($systems['coinmarketcap']->is_active)->toBeTrue()
        ->and($systems['alternativeme']->is_active)->toBeTrue()
        ->and($systems['taapi']->is_active)->toBeTrue();
});

test('account processing scope excludes inactive exchanges without changing account state', function (): void {
    $activeSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'active-account-scope',
        'name' => 'Active account scope',
        'is_active' => true,
    ]);
    $inactiveSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'inactive-account-scope',
        'name' => 'Inactive account scope',
        'is_active' => false,
    ]);
    $activeAccount = Account::factory()->create([
        'api_system_id' => $activeSystem->id,
        'is_active' => true,
        'can_trade' => true,
    ]);
    $inactiveExchangeAccount = Account::factory()->create([
        'api_system_id' => $inactiveSystem->id,
        'is_active' => true,
        'can_trade' => true,
    ]);

    expect($activeAccount->is_active)->toBeTrue()
        ->and($inactiveExchangeAccount->is_active)->toBeTrue()
        ->and(Account::query()
            ->onActiveApiSystem()
            ->whereKey([$activeAccount->id, $inactiveExchangeAccount->id])
            ->pluck('id')
            ->all())->toBe([$activeAccount->id]);
});

test('inactive API systems stop already queued API jobs before execution', function (): void {
    $inactiveSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'test',
        'name' => 'Inactive queued API job',
        'is_active' => false,
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $inactiveSystem->id,
        'is_active' => true,
        'can_trade' => true,
    ]);
    $step = StepTester::createSteps([
        ['arguments' => ['accountId' => $account->id]],
    ], TestApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'stopped']])
        ->test();

    $step->refresh();
    $events = array_column($step->response['execution_path'] ?? [], 'event');

    expect($events)->toContain('assignExceptionHandler')
        ->and($events)->not->toContain('computeApiable:start');
});

test('position recovery excludes accounts on inactive API systems', function (): void {
    $activeSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'active-recovery-scope',
        'name' => 'Active recovery scope',
        'is_active' => true,
    ]);
    $inactiveSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'inactive-recovery-scope',
        'name' => 'Inactive recovery scope',
        'is_active' => false,
    ]);
    $activeAccount = Account::factory()->create([
        'api_system_id' => $activeSystem->id,
        'is_active' => true,
    ]);
    $inactiveAccount = Account::factory()->create([
        'api_system_id' => $inactiveSystem->id,
        'is_active' => true,
    ]);

    $resolveTargetAccounts = new ReflectionMethod(RecoverPositionsCommand::class, 'resolveTargetAccounts');
    $command = app(RecoverPositionsCommand::class);

    expect($resolveTargetAccounts->invoke($command, null)->modelKeys())->toBe([$activeAccount->id])
        ->and($resolveTargetAccounts->invoke($command, $activeAccount->id)->modelKeys())->toBe([$activeAccount->id])
        ->and($resolveTargetAccounts->invoke($command, $inactiveAccount->id))->toBeEmpty();
});
