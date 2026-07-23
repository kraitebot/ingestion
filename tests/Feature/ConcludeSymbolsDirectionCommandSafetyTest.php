<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Commands\Cronjobs\ConcludeSymbolsDirectionCommand;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use StepDispatcher\Models\Step;

it('does not expose the retired preserve option', function (): void {
    $command = app(ConcludeSymbolsDirectionCommand::class);

    expect($command->getDefinition()->hasOption('preserve'))->toBeFalse();
});

it('creates conclude steps without automatic cleanup arguments', function (): void {
    Kraite::firstOrFail()->update(['timeframes' => ['4h']]);
    Illuminate\Support\Once::flush();

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $symbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'RETAINED',
        'quote' => 'USDT',
        'api_statuses' => ['has_taapi_data' => true],
    ]);

    expect(Step::query()
        ->where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
        ->whereJsonContains('arguments->exchangeSymbolId', $symbol->id)
        ->exists())->toBeFalse();

    $this->artisan('kraite:cron-conclude-symbols-direction')->assertSuccessful();

    $arguments = Step::query()
        ->where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
        ->whereJsonContains('arguments->exchangeSymbolId', $symbol->id)
        ->sole()
        ->arguments;

    expect($arguments)->toHaveCount(3)
        ->toMatchArray([
            'exchangeSymbolId' => $symbol->id,
            'timeframe' => '4h',
            'previousConclusions' => [],
        ])
        ->not->toHaveKey('shouldCleanup');
});

it('refuses the destructive clean option outside local and testing', function (): void {
    $this->app['env'] = 'production';

    DB::shouldReceive('statement')->never();
    DB::shouldReceive('table')->never();

    $this->artisan('kraite:cron-conclude-symbols-direction', ['--clean' => true])
        ->expectsOutputToContain('--clean refused')
        ->assertFailed();
});

it('does not create a second workflow while another conclude command owns the lock', function (): void {
    Kraite::firstOrFail()->update(['timeframes' => ['4h']]);
    Illuminate\Support\Once::flush();

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $symbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'LOCKED',
        'quote' => 'USDT',
        'api_statuses' => ['has_taapi_data' => true],
    ]);

    $lock = Cache::lock('kraite:conclude-symbols-direction', 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('kraite:cron-conclude-symbols-direction')->assertSuccessful();
    } finally {
        $lock->release();
    }

    expect(Step::query()
        ->whereJsonContains('arguments->exchangeSymbolId', $symbol->id)
        ->exists())->toBeFalse();
});
