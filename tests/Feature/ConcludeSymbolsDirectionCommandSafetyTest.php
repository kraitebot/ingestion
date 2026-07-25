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

it('refuses the destructive reset option outside local and testing', function (): void {
    $symbol = ExchangeSymbol::factory()->create([
        'direction' => 'LONG',
        'indicators_timeframe' => '4h',
    ]);
    $this->app['env'] = 'production';

    $this->artisan('kraite:cron-conclude-symbols-direction', ['--reset' => true])
        ->expectsOutputToContain('--reset refused')
        ->assertFailed();

    expect($symbol->fresh()->direction)->toBe('LONG')
        ->and($symbol->fresh()->indicators_timeframe)->toBe('4h');
});

it('keeps exchange ban records out of indicator cleanup', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Commands/Cronjobs/ConcludeSymbolsDirectionCommand.php'),
    );

    expect($source)->not->toContain("DB::table('forbidden_hostnames')->truncate()");
});

it('commits one symbol workflow at a time instead of wrapping the full pass', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Commands/Cronjobs/ConcludeSymbolsDirectionCommand.php'),
    );

    expect($source)
        ->toContain('private function createWorkflowForSymbol')
        ->toContain('return DB::transaction(function () use ($symbolId, $startingTimeframe): string')
        ->not->toContain('DB::transaction(function () use ($symbolsToProcess');
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
