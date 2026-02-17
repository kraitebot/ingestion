<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\ApiSystem\DiscoverCMCTokensForOrphanedSymbolsJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\DiscoverCMCTokenForExchangeSymbolJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

/**
 * Helper to create orphaned exchange symbols for lifecycle testing
 */
function createOrphanedSymbolsForCMCLifecycle(int $count, bool $cmcApiCalled = false): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance_lifecycle_'.uniqid(),
        'name' => 'Binance Lifecycle Test',
    ]);

    $exchangeSymbols = [];
    for ($i = 0; $i < $count; $i++) {
        $exchangeSymbols[] = ExchangeSymbol::factory()->create([
            'token' => 'ORPHAN_'.uniqid(),
            'quote' => 'USDT',
            'api_system_id' => $apiSystem->id,
            'symbol_id' => null,
            'api_statuses' => [
                'cmc_api_called' => $cmcApiCalled,
                'taapi_verified' => false,
                'has_taapi_data' => false,
            ],
        ]);
    }

    return $exchangeSymbols;
}

/**
 * Helper to create a linked exchange symbol
 */
function createLinkedSymbolForCMCLifecycle(#[SensitiveParameter] ?string $token = null): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance_linked_'.uniqid(),
        'name' => 'Binance Linked Test',
    ]);

    $symbol = Symbol::factory()->create([
        'token' => $token ?? 'LINKED_'.uniqid(),
    ]);

    return ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'api_statuses' => [
            'cmc_api_called' => false,
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
    ]);
}

/**
 * Helper to execute lifecycle job with a mock step
 */
function executeCMCLifecycleJobWithStep(): array
{
    $childBlockUuid = (string) Illuminate\Support\Str::uuid();

    $step = Step::create([
        'class' => DiscoverCMCTokensForOrphanedSymbolsJob::class,
        'arguments' => [],
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
    ]);

    $job = new DiscoverCMCTokensForOrphanedSymbolsJob;
    $job->step = $step;

    $result = $job->compute();

    $step->refresh();

    return [
        'result' => $result,
        'step' => $step,
        'child_block_uuid' => $childBlockUuid,
    ];
}

test('creates child steps for orphaned exchange symbols', function () {
    // Create 3 orphaned symbols
    $orphanedSymbols = createOrphanedSymbolsForCMCLifecycle(3);

    $execution = executeCMCLifecycleJobWithStep();
    $result = $execution['result'];
    $childBlockUuid = $execution['child_block_uuid'];

    expect($result['orphaned_count'])->toBe(3);
    expect($result['steps_created'])->toBe(3);

    // Verify child steps were created with correct block_uuid
    $childSteps = Step::where('block_uuid', $childBlockUuid)->get();
    expect($childSteps)->toHaveCount(3);

    // Verify each child step has correct job class and arguments
    foreach ($childSteps as $childStep) {
        expect($childStep->class)->toBe(DiscoverCMCTokenForExchangeSymbolJob::class);
        expect($childStep->arguments)->toHaveKey('exchangeSymbolId');
        expect($childStep->index)->toBe(1);
    }

    // Verify each orphaned symbol has a corresponding step
    $stepExchangeSymbolIds = $childSteps->pluck('arguments.exchangeSymbolId')->toArray();
    foreach ($orphanedSymbols as $orphanedSymbol) {
        expect($stepExchangeSymbolIds)->toContain($orphanedSymbol->id);
    }
});

test('does not create steps for symbols with cmc_api_called true', function () {
    // Create 2 orphaned symbols that have already been processed
    createOrphanedSymbolsForCMCLifecycle(2, cmcApiCalled: true);

    // Create 1 new orphaned symbol
    createOrphanedSymbolsForCMCLifecycle(1, cmcApiCalled: false);

    $execution = executeCMCLifecycleJobWithStep();
    $result = $execution['result'];

    expect($result['orphaned_count'])->toBe(1);
    expect($result['steps_created'])->toBe(1);

    // Verify only 1 child step created
    $childSteps = Step::where('block_uuid', $execution['child_block_uuid'])->get();
    expect($childSteps)->toHaveCount(1);
});

test('does not create steps for linked symbols', function () {
    // Create linked symbols (have symbol_id)
    createLinkedSymbolForCMCLifecycle('LINKED1');
    createLinkedSymbolForCMCLifecycle('LINKED2');

    $execution = executeCMCLifecycleJobWithStep();
    $result = $execution['result'];

    expect($result['orphaned_count'])->toBe(0);
    expect($result['steps_created'])->toBe(0);
});

test('clears child_block_uuid when no children to create', function () {
    // No orphaned symbols - all are linked or already processed
    createLinkedSymbolForCMCLifecycle();

    $execution = executeCMCLifecycleJobWithStep();
    $step = $execution['step'];

    // child_block_uuid should be cleared so StepDispatcher can complete the step
    expect($step->child_block_uuid)->toBeNull();
});

test('returns informative message when all symbols already processed', function () {
    // Create orphaned symbols that were already processed
    createOrphanedSymbolsForCMCLifecycle(5, cmcApiCalled: true);

    $execution = executeCMCLifecycleJobWithStep();
    $result = $execution['result'];

    expect($result['orphaned_count'])->toBe(0);
    expect($result['already_processed'])->toBe(5);
    expect($result['steps_created'])->toBe(0);
    expect($result['message'])->toContain('No new orphaned symbols to process');
    expect($result['message'])->toContain('5 already checked via CMC API');
});

test('returns informative message when no orphaned symbols exist', function () {
    // Only linked symbols
    createLinkedSymbolForCMCLifecycle();
    createLinkedSymbolForCMCLifecycle();

    $execution = executeCMCLifecycleJobWithStep();
    $result = $execution['result'];

    expect($result['orphaned_count'])->toBe(0);
    expect($result['already_processed'])->toBe(0);
    expect($result['steps_created'])->toBe(0);
    expect($result['message'])->toBe('No orphaned exchange symbols found');
});

test('handles mixed scenario with linked, orphaned, and already processed', function () {
    // 2 linked symbols
    createLinkedSymbolForCMCLifecycle('LINKED_A');
    createLinkedSymbolForCMCLifecycle('LINKED_B');

    // 3 orphaned symbols already processed
    createOrphanedSymbolsForCMCLifecycle(3, cmcApiCalled: true);

    // 2 new orphaned symbols
    $newOrphans = createOrphanedSymbolsForCMCLifecycle(2, cmcApiCalled: false);

    $execution = executeCMCLifecycleJobWithStep();
    $result = $execution['result'];

    expect($result['orphaned_count'])->toBe(2);
    expect($result['steps_created'])->toBe(2);

    // Verify steps created for new orphans only
    $childSteps = Step::where('block_uuid', $execution['child_block_uuid'])->get();
    expect($childSteps)->toHaveCount(2);

    $stepExchangeSymbolIds = $childSteps->pluck('arguments.exchangeSymbolId')->toArray();
    foreach ($newOrphans as $orphan) {
        expect($stepExchangeSymbolIds)->toContain($orphan->id);
    }
});

test('preserves child_block_uuid when children are created', function () {
    // Create orphaned symbol
    createOrphanedSymbolsForCMCLifecycle(1);

    $execution = executeCMCLifecycleJobWithStep();
    $step = $execution['step'];

    // child_block_uuid should NOT be cleared when children are created
    expect($step->child_block_uuid)->not->toBeNull();
    expect($step->child_block_uuid)->toBe($execution['child_block_uuid']);
});

test('child steps use parent child_block_uuid as their block_uuid', function () {
    createOrphanedSymbolsForCMCLifecycle(2);

    $execution = executeCMCLifecycleJobWithStep();
    $parentChildBlockUuid = $execution['child_block_uuid'];

    // All child steps should have block_uuid = parent's child_block_uuid
    $childSteps = Step::where('class', DiscoverCMCTokenForExchangeSymbolJob::class)->get();
    expect($childSteps)->toHaveCount(2);

    foreach ($childSteps as $childStep) {
        expect($childStep->block_uuid)->toBe($parentChildBlockUuid);
    }
});

test('integration: deleted exchange symbol is recreated and rediscovered', function () {
    // Create a symbol in the symbols table
    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'integration_test_'.uniqid(),
        'name' => 'Integration Test Exchange',
    ]);

    // First run: Create orphaned exchange symbol
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => null,
        'api_statuses' => [
            'cmc_api_called' => false,
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
    ]);

    $execution1 = executeCMCLifecycleJobWithStep();
    expect($execution1['result']['orphaned_count'])->toBe(1);
    expect($execution1['result']['steps_created'])->toBe(1);

    // Simulate that the child job ran and linked it
    $exchangeSymbol->update([
        'symbol_id' => $btcSymbol->id,
    ]);

    // Delete the exchange symbol (simulating it being removed from exchange)
    $exchangeSymbol->delete();

    // Recreate as if upsert job added it again
    $recreatedSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => null, // Fresh record - no link yet
        'api_statuses' => [
            'cmc_api_called' => false, // Fresh record
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
    ]);

    // Second run: Should detect the new orphan
    $execution2 = executeCMCLifecycleJobWithStep();
    expect($execution2['result']['orphaned_count'])->toBe(1);
    expect($execution2['result']['steps_created'])->toBe(1);

    // Verify step was created for the recreated symbol
    $childSteps = Step::where('block_uuid', $execution2['child_block_uuid'])->get();
    expect($childSteps)->toHaveCount(1);
    expect($childSteps->first()->arguments['exchangeSymbolId'])->toBe($recreatedSymbol->id);
});

test('handles large number of orphaned symbols efficiently', function () {
    // Create 50 orphaned symbols
    $orphanedSymbols = createOrphanedSymbolsForCMCLifecycle(50);

    $startTime = microtime(true);
    $execution = executeCMCLifecycleJobWithStep();
    $endTime = microtime(true);

    expect($execution['result']['orphaned_count'])->toBe(50);
    expect($execution['result']['steps_created'])->toBe(50);

    // Should complete in reasonable time (less than 5 seconds)
    $duration = $endTime - $startTime;
    expect($duration)->toBeLessThan(5.0);

    // Verify all steps created
    $childSteps = Step::where('block_uuid', $execution['child_block_uuid'])->get();
    expect($childSteps)->toHaveCount(50);
});
