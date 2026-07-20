<?php

declare(strict_types=1);

use Cron\CronExpression;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\ApiSystem\UpsertExchangeSymbolsFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\Bitget\SyncLeverageBracketsJob as BitgetSyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\Bybit\SyncLeverageBracketsJob as BybitSyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\DiscoverCMCTokensForOrphanedSymbolsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\Kucoin\SyncLeverageBracketsJob as KucoinSyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\SyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\TouchTaapiDataForExchangeSymbolsJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\VerifyPriceAlignmentsJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use StepDispatcher\Models\Step;

test('targeted hourly exchange refresh creates no leverage bracket step', function (
    string $canonical,
    string $expectedLeverageJob,
): void {
    $testToken = Str::lower(Str::random(8));
    $targetExchange = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => "Target {$canonical} {$testToken}",
    ]);
    $untargetedExchange = ApiSystem::factory()->exchange()->create([
        'canonical' => "untargeted-{$testToken}",
        'name' => "Untargeted {$testToken}",
    ]);

    expect(Step::query()
        ->where('class', UpsertExchangeSymbolsFromExchangeJob::class)
        ->whereJsonContains('arguments->apiSystemId', $targetExchange->id)
        ->exists())->toBeFalse()
        ->and(Step::query()
            ->where('class', UpsertExchangeSymbolsFromExchangeJob::class)
            ->whereJsonContains('arguments->apiSystemId', $untargetedExchange->id)
            ->exists())->toBeFalse();

    $this->artisan('kraite:cron-refresh-exchange-symbols', ['--exchange' => $canonical])
        ->assertSuccessful();

    $upsertStep = Step::query()
        ->where('class', UpsertExchangeSymbolsFromExchangeJob::class)
        ->whereJsonContains('arguments->apiSystemId', $targetExchange->id)
        ->sole();
    $workflowSteps = Step::query()
        ->where('block_uuid', $upsertStep->block_uuid)
        ->get();

    expect($workflowSteps)->toHaveCount(4)
        ->and($workflowSteps->pluck('index')->unique()->sort()->values()->all())->toBe([1, 2, 3])
        ->and($upsertStep->index)->toBe(1)
        ->and($workflowSteps->where('class', DiscoverCMCTokensForOrphanedSymbolsJob::class)->sole()->index)->toBe(2)
        ->and($workflowSteps->where('class', TouchTaapiDataForExchangeSymbolsJob::class)->sole()->index)->toBe(2)
        ->and($workflowSteps->where('class', $expectedLeverageJob))->toBeEmpty()
        ->and($workflowSteps->where('class', VerifyPriceAlignmentsJob::class)->sole()->index)->toBe(3)
        ->and(Step::query()
            ->where('class', UpsertExchangeSymbolsFromExchangeJob::class)
            ->whereJsonContains('arguments->apiSystemId', $untargetedExchange->id)
            ->exists())->toBeFalse();
})->with([
    'Binance target' => ['binance', SyncLeverageBracketsJob::class],
    'Bybit target' => ['bybit', BybitSyncLeverageBracketsJob::class],
    'KuCoin target' => ['kucoin', KucoinSyncLeverageBracketsJob::class],
    'Bitget target' => ['bitget', BitgetSyncLeverageBracketsJob::class],
]);

test('targeted full bracket refresh opts every exchange into a complete leverage sweep', function (
    string $canonical,
    string $expectedLeverageJob,
): void {
    $exchange = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => "Full bracket refresh {$canonical} ".Str::lower(Str::random(8)),
    ]);

    $this->artisan('kraite:cron-refresh-exchange-symbols', [
        '--exchange' => $canonical,
        '--with-brackets' => true,
    ])->assertSuccessful();

    $leverageStep = Step::query()
        ->where('class', $expectedLeverageJob)
        ->whereJsonContains('arguments->apiSystemId', $exchange->id)
        ->sole();

    expect($leverageStep->arguments)->toBe(['apiSystemId' => $exchange->id]);
})->with([
    'Binance full sweep' => ['binance', SyncLeverageBracketsJob::class],
    'Bybit full sweep' => ['bybit', BybitSyncLeverageBracketsJob::class],
    'KuCoin full sweep' => ['kucoin', KucoinSyncLeverageBracketsJob::class],
    'Bitget full sweep' => ['bitget', BitgetSyncLeverageBracketsJob::class],
]);

test('full refresh preserves Binance-first workflow ordering', function (): void {
    $testToken = Str::lower(Str::random(8));
    $exchanges = collect(['binance', 'bybit', 'kucoin', 'bitget'])
        ->mapWithKeys(fn (string $canonical): array => [
            $canonical => ApiSystem::factory()->exchange()->create([
                'canonical' => $canonical,
                'name' => "Full refresh {$canonical} {$testToken}",
            ]),
        ]);
    $nonExchange = ApiSystem::factory()->create([
        'canonical' => "non-exchange-{$testToken}",
        'name' => "Non-exchange {$testToken}",
    ]);

    foreach ($exchanges as $exchange) {
        expect(Step::query()
            ->where('class', UpsertExchangeSymbolsFromExchangeJob::class)
            ->whereJsonContains('arguments->apiSystemId', $exchange->id)
            ->exists())->toBeFalse();
    }

    $this->artisan('kraite:cron-refresh-exchange-symbols')->assertSuccessful();

    $upsertSteps = Step::query()
        ->where('class', UpsertExchangeSymbolsFromExchangeJob::class)
        ->get();
    $blockUuid = $upsertSteps->sole(fn (Step $step): bool => $step->index === 1)->block_uuid;
    $workflowSteps = Step::query()->where('block_uuid', $blockUuid)->get();

    expect($workflowSteps)->toHaveCount(7)
        ->and($workflowSteps->pluck('index')->unique()->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and($upsertSteps->where('index', 1)->sole()->arguments['apiSystemId'])->toBe($exchanges['binance']->id)
        ->and($upsertSteps->where('index', 2)->pluck('arguments')->pluck('apiSystemId')->sort()->values()->all())->toBe(
            $exchanges->except('binance')->pluck('id')->sort()->values()->all()
        )
        ->and($workflowSteps->where('index', 3))->toHaveCount(2)
        ->and($workflowSteps
            ->whereIn('class', [
                SyncLeverageBracketsJob::class,
                BybitSyncLeverageBracketsJob::class,
                KucoinSyncLeverageBracketsJob::class,
                BitgetSyncLeverageBracketsJob::class,
            ])
            ->isEmpty())->toBeTrue()
        ->and($workflowSteps->where('class', VerifyPriceAlignmentsJob::class)->sole()->index)->toBe(4)
        ->and(Step::query()
            ->whereJsonContains('arguments->apiSystemId', $nonExchange->id)
            ->exists())->toBeFalse();
});

test('exchange catalogue schedules exactly one refresh each hour and a full bracket sweep every six hours', function (): void {
    config(['kraite.server_role' => 'ingestion']);
    Kraite::query()->firstOrFail()->update(['is_cooling_down' => false]);
    require base_path('routes/console.php');

    Artisan::call('schedule:list', ['--json' => true]);

    $events = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))
        ->filter(fn (array $event): bool => str_contains($event['command'], 'kraite:cron-refresh-exchange-symbols'))
        ->values();

    $incrementalEvent = $events->sole(
        fn (array $event): bool => ! str_contains($event['command'], '--with-brackets')
    );
    $fullEvent = $events->sole(
        fn (array $event): bool => str_contains($event['command'], '--with-brackets')
    );

    expect($events)->toHaveCount(2)
        ->and($incrementalEvent['expression'])->toBe('15 1-5,7-11,13-17,19-23 * * *')
        ->and($fullEvent['expression'])->toBe('15 */6 * * *');

    foreach ([0, 1, 6, 7, 12, 13, 18, 19, 23] as $hour) {
        $currentTime = now()->startOfDay()->setHour($hour)->setMinute(15);

        $dueEvents = $events
            ->filter(fn (array $event): bool => (new CronExpression($event['expression']))
                ->isDue($currentTime, $event['timezone']))
            ->values();

        expect($dueEvents)->toHaveCount(1);

        if ($hour % 6 === 0) {
            expect($dueEvents->sole()['command'])->toContain('--with-brackets');
        } else {
            expect($dueEvents->sole()['command'])->not->toContain('--with-brackets');
        }
    }
});
