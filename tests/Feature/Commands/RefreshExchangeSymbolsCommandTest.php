<?php

declare(strict_types=1);

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
use StepDispatcher\Models\Step;

test('targeted exchange refresh creates a contiguous runnable workflow', function (
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

    expect($workflowSteps)->toHaveCount(5)
        ->and($workflowSteps->pluck('index')->unique()->sort()->values()->all())->toBe([1, 2, 3])
        ->and($upsertStep->index)->toBe(1)
        ->and($workflowSteps->where('class', DiscoverCMCTokensForOrphanedSymbolsJob::class)->sole()->index)->toBe(2)
        ->and($workflowSteps->where('class', TouchTaapiDataForExchangeSymbolsJob::class)->sole()->index)->toBe(2)
        ->and($workflowSteps->where('class', $expectedLeverageJob)->sole()->index)->toBe(2)
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

    expect($workflowSteps)->toHaveCount(11)
        ->and($workflowSteps->pluck('index')->unique()->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and($upsertSteps->where('index', 1)->sole()->arguments['apiSystemId'])->toBe($exchanges['binance']->id)
        ->and($upsertSteps->where('index', 2)->pluck('arguments')->pluck('apiSystemId')->sort()->values()->all())->toBe(
            $exchanges->except('binance')->pluck('id')->sort()->values()->all()
        )
        ->and($workflowSteps->where('index', 3))->toHaveCount(6)
        ->and($workflowSteps->where('class', VerifyPriceAlignmentsJob::class)->sole()->index)->toBe(4)
        ->and(Step::query()
            ->whereJsonContains('arguments->apiSystemId', $nonExchange->id)
            ->exists())->toBeFalse();
});
